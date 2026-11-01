<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now('Africa/Casablanca')->setDate(2026, 10, 24)->startOfDay());
        $c = Company::factory()->create();
        [$m,$s,$v,$v2,$client] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create(), Vehicle::factory()->create(), Vehicle::factory()->create(), Customer::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);

        return [$c, $m, $s, $v, $v2, $client];
    }

    private function expense(array $allocations, int $paid = 0, string $date = '2026-10-24', string $method = 'cash'): int
    {
        return $this->postJson('/api/v1/expenses', ['title' => 'Frais TEST', 'category' => 'wash', 'supplier' => 'Garage TEST', 'invoice_reference' => (string) Str::uuid(), 'incurred_on' => $date, 'amount_centimes' => array_sum(array_column($allocations, 'amount_centimes')), 'allocations' => $allocations, 'paid_centimes' => $paid, 'paid_on' => $date, 'method' => $method, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function reservation(array $a, string $status = 'demande', int $total = 70000): array
    {
        [$c,$m,,$v,,$client] = $a;
        $base = ['company_id' => $c->id, 'actor_membership_id' => $m->id, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now()];
        $id = DB::table('reservations')->insertGetId([...$base, 'vehicle_id' => $v->id, 'customer_id' => $client->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3), 'status' => $status, 'confirmed_at' => $status === 'demande' ? null : now(), 'preparation_minutes' => $status === 'demande' ? null : 0, 'blocked_until' => $status === 'demande' ? null : now()->addDays(3), 'frozen_total_centimes' => $status === 'demande' ? null : $total]);

        return [$id, [...$base, 'reservation_id' => $id]];
    }

    private function receipt(array $base, int $amount, string $method = 'especes', bool $verified = true, string $date = '2026-10-24'): int
    {
        $id = DB::table('rental_payments')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'method' => $method, 'amount_centimes' => $amount, 'received_on' => $date]);
        if ($verified) {
            DB::table('rental_payment_verifications')->insert([...$base, 'request_key' => (string) Str::uuid(), 'payment_id' => $id, 'reason' => 'TEST']);
        }

        return $id;
    }

    public function test_empty_month_and_current_balances_are_explicit(): void
    {
        $this->agency();
        $this->getJson('/api/v1/reports?month=2026-10')->assertOk()->assertJsonPath('summary.incoming_centimes', 0)->assertJsonPath('summary.outgoing_centimes', 0)->assertJsonCount(24, 'daily')->assertJsonCount(6, 'trend')->assertJsonPath('balances.scope', 'current_all_dates');
        $this->getJson('/api/v1/reports?month=2024-02')->assertOk()->assertJsonCount(29, 'daily')->assertJsonPath('period.to', '2024-02-29');
        $this->getJson('/api/v1/reports?month=2026-11')->assertUnprocessable();
        $this->getJson('/api/v1/reports?month=2026-13')->assertUnprocessable();
    }

    public function test_verified_receipts_exclude_deposits_pending_cheques_transfers_and_opening(): void
    {
        $a = $this->agency();
        [$r,$base] = $this->reservation($a);
        $this->receipt($base, 10000);
        $this->receipt($base, 20000, 'virement', false);
        $this->receipt($base, 30000, 'carte');
        $this->expense([['vehicle_id' => null, 'amount_centimes' => 40000]], 40000, '2026-10-24', 'cheque');
        foreach ([['especes', 50000], ['carte_bloquee', 90000]] as [$method,$amount]) {
            $id = DB::table('reservation_departure_entries')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'action' => 'caution_received', 'amount_centimes' => $amount, 'method' => $method, 'received_on' => '2026-10-24', 'reason' => 'TEST', 'data' => '{}']);
            if ($method === 'especes') {
                DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'deposit_allocate', 'scope' => 'rent', 'amount_centimes' => 10000, 'deposit_receipt_id' => $id, 'occurred_on' => '2026-10-24', 'reason' => 'TEST', 'snapshot' => '{}']);
                DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'deposit_return', 'scope' => 'deposit', 'amount_centimes' => 20000, 'method' => 'especes', 'deposit_receipt_id' => $id, 'occurred_on' => '2026-10-24', 'reason' => 'TEST', 'snapshot' => '{}']);
            }
        }
        foreach ([['opening', 100000], ['bank_deposit', 1000]] as [$kind,$amount]) {
            $this->postJson('/api/v1/cash/entries', ['kind' => $kind, 'amount_centimes' => $amount, 'occurred_on' => '2026-10-24', 'reason' => 'TEST fonds', 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        }
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.incoming_centimes', 40000)->assertJsonPath('summary.outgoing_centimes', 0)->assertJsonPath('summary.count', 2)->assertJsonPath('excluded.pending.incoming_centimes', 20000)->assertJsonPath('excluded.cheque.outgoing_centimes', 40000)->assertJsonPath('excluded.deposit.incoming_centimes', 50000)->assertJsonPath('excluded.deposit.outgoing_centimes', 20000)->assertJsonPath('excluded.blocked.incoming_centimes', 90000)->assertJsonPath('excluded.transfer.net_centimes', 0);
        $this->getJson('/api/v1/reports/movements?group=deposit')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/reports/movements?group=pending')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_damage_allocation_is_explained_once_without_cash_income_and_is_scoped(): void
    {
        $a = $this->agency();
        [$r, $base] = $this->reservation($a, 'retournee');
        $deposit = DB::table('reservation_departure_entries')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'action' => 'caution_received', 'amount_centimes' => 300000, 'method' => 'especes', 'received_on' => '2026-10-20', 'reason' => 'TEST', 'data' => '{}']);
        foreach ([['deposit_allocate', 'damage', 60000, '2026-10-24'], ['deposit_return', 'deposit', 240000, '2026-10-24']] as [$kind, $scope, $amount, $day]) {
            DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => $kind, 'scope' => $scope, 'amount_centimes' => $amount, 'deposit_receipt_id' => $deposit, 'method' => $kind === 'deposit_return' ? 'especes' : null, 'occurred_on' => $day, 'reason' => 'TEST', 'snapshot' => '{}']);
        }
        $url = '/api/v1/reports/movements?month=2026-10&group=deposit_damage';
        $report = '/api/v1/reports?month=2026-10&vehicle_id='.$a[3]->id;
        $this->getJson($report)->assertOk()->assertJsonPath('damage_allocations.amount_centimes', 60000)->assertJsonPath('damage_allocations.count', 1)->assertJsonPath('summary.incoming_centimes', 0)->assertJsonPath('summary.outgoing_centimes', 0)->assertJsonPath('excluded.deposit.incoming_centimes', 300000)->assertJsonPath('excluded.deposit.outgoing_centimes', 240000)->assertJsonPath('balances.customers.deposit_held_centimes', 0);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson($url.'&vehicle_id='.$a[3]->id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.allocated_centimes', 60000)->assertJsonPath('summary.incoming_centimes', 0)->assertJsonPath('summary.outgoing_centimes', 0)->assertJsonPath('data.0.source_id', $r);
        }
        $this->getJson($url.'&vehicle_id='.$a[4]->id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/reports/movements?month=2026-09&group=deposit_damage')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('rental_settlement_entries', 2);
        $this->actingAs($a[2]->user)->withSession(['company_id' => $a[0]->id]);
        $this->getJson($url)->assertForbidden();
        $this->agency();
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($report)->assertNotFound();
    }

    public function test_shared_invoice_is_once_at_agency_and_not_invented_per_car(): void
    {
        [,,,$v,$v2] = $this->agency();
        $this->expense([['vehicle_id' => $v->id, 'amount_centimes' => 90000], ['vehicle_id' => $v2->id, 'amount_centimes' => 30000]], 100000);
        $this->expense([['vehicle_id' => $v->id, 'amount_centimes' => 50000]], 30000);
        $this->expense([['vehicle_id' => null, 'amount_centimes' => 8000]], 8000);
        $all = $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.outgoing_centimes', 138000)->assertJsonPath('summary.count', 3)->assertJsonPath('unassigned.shared.outgoing_centimes', 100000)->assertJsonPath('unassigned.agency.outgoing_centimes', 8000)->assertJsonPath('balances.payables.total_centimes', 40000)->json();
        $this->assertSame($all['summary']['outgoing_centimes'], array_sum(array_column($all['vehicles'], 'outgoing_centimes')) + 8000 + 100000);
        $this->getJson('/api/v1/reports?vehicle_id='.$v->id)->assertOk()->assertJsonPath('summary.outgoing_centimes', 30000)->assertJsonPath('shared_context.outgoing_centimes', 100000)->assertJsonPath('balances.payables.total_centimes', 20000)->assertJsonPath('balances.shared_payable_centimes', 20000);
        $this->getJson('/api/v1/reports?vehicle_id='.$v2->id)->assertOk()->assertJsonPath('summary.outgoing_centimes', 0)->assertJsonPath('shared_context.outgoing_centimes', 100000);
        $this->getJson('/api/v1/reports/movements?vehicle_id='.$v->id.'&group=shared')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.attribution', 'shared');
    }

    public function test_month_uses_actual_payment_dates_and_pagination_preserves_totals(): void
    {
        $a = $this->agency();
        [$r,$base] = $this->reservation($a);
        $this->receipt($base, 500, 'especes', true, '2026-09-30');
        for ($i = 0; $i < 22; $i++) {
            $this->receipt($base, 101, 'especes', true, '2026-10-01');
        }
        $this->expense([['vehicle_id' => null, 'amount_centimes' => 7000]], 0, '2026-09-20');
        $data = $this->getJson('/api/v1/reports?month=2026-10')->assertOk()->assertJsonPath('summary.incoming_centimes', 2222)->assertJsonPath('daily.0.incoming_centimes', 2222)->assertJsonPath('trend.4.incoming_centimes', 500)->assertJsonPath('trend.5.incoming_centimes', 2222)->assertJsonPath('balances.payables.total_centimes', 7000)->json();
        $this->assertSame($data['summary']['incoming_centimes'], array_sum(array_column($data['daily'], 'incoming_centimes')));
        $this->getJson('/api/v1/reports/movements?month=2026-10&page=2')->assertOk()->assertJsonPath('total', 22)->assertJsonCount(2, 'data')->assertJsonPath('summary.incoming_centimes', 2222);
        $this->getJson('/api/v1/reports/movements?month=2026-10&direction=out')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_maintenance_is_not_counted_as_both_invoice_and_payment_and_correction_is_effective(): void
    {
        [,,,$v] = $this->agency();
        $base = "/api/v1/vehicles/$v->id/maintenance";
        $input = ['mode' => 'completed', 'performed_on' => '2026-10-20', 'garage' => 'Garage ONCE', 'items' => [['kind' => 'oil', 'title' => 'Vidange', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000, 'completed_on' => '2026-10-20']], 'paid_centimes' => 65000, 'paid_on' => '2026-10-20', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($base.'/files', $input)->assertCreated()->json('id');
        $this->postJson($base.'/files', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.outgoing_centimes', 65000)->assertJsonPath('summary.count', 1);
        $file = $this->getJson("$base/$id/file")->json();
        $pid = $file['payments'][0]['id'];
        $this->postJson("$base/$id/payments/$pid/corrections", ['amount_centimes' => 60000, 'reason' => 'Correction du montant saisi', 'correction_revision' => 0, 'work_revision' => $file['data']['revision'], 'expected_paid_centimes' => 65000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $d = $this->getJson("$base/$id/file")->json('data');
        $this->postJson("$base/$id/refunds", ['amount_centimes' => 60000, 'received_on' => '2026-10-21', 'method' => 'cash', 'reason' => 'Versement retourné intégralement', 'work_revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => $d['paid_centimes'], 'expected_refunded_centimes' => $d['refunded_centimes'], 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/reports?vehicle_id='.$v->id)->assertOk()->assertJsonPath('summary.outgoing_centimes', 60000)->assertJsonPath('summary.incoming_centimes', 60000)->assertJsonPath('summary.net_centimes', 0)->assertJsonPath('summary.count', 2)->assertJsonPath('balances.payables.total_centimes', 65000);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_apport_installments_and_opening_paid_amount_are_distinct(): void
    {
        [,,,$v,$v2] = $this->agency();
        $loan = $this->postJson('/api/v1/loans', ['vehicle_id' => $v->id, 'lender' => 'Banque TEST', 'reference' => 'ACHAT-TEST', 'tracking_from' => '2026-10-24', 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid(),
            'purchase' => ['seller' => 'Concession TEST', 'purchased_on' => '2026-10-24', 'down_payment_due_on' => '2026-10-24', 'price_centimes' => 20000000, 'down_payment_centimes' => 5000000],
            'installments' => [['due_on' => '2026-11-24', 'amount_centimes' => 7500000, 'opening_paid_centimes' => 0], ['due_on' => '2026-12-24', 'amount_centimes' => 7500000, 'opening_paid_centimes' => 0]],
        ])->assertCreated()->json('id');
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.outgoing_centimes', 0)->assertJsonPath('balances.payables.total_centimes', 20000000)->assertJsonPath('balances.payables.month_due_centimes', 5000000);
        $payment = ['amount_centimes' => 5000000, 'method' => 'transfer', 'paid_on' => '2026-10-24', 'expected_remaining_centimes' => 5000000, 'receipt_confirmed' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/loans/$loan/purchase-payments", $payment)->assertCreated();
        $this->postJson("/api/v1/loans/$loan/purchase-payments", $payment)->assertCreated();
        $installment = $this->getJson("/api/v1/loans/$loan")->json('installments.0.id');
        $this->postJson("/api/v1/loans/$loan/installments/$installment/payments", [...$payment, 'amount_centimes' => 7500000, 'expected_remaining_centimes' => 7500000, 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/v1/loans', ['vehicle_id' => $v2->id, 'lender' => 'Banque TEST', 'reference' => 'HISTORY', 'tracking_from' => '2026-10-01', 'installments' => [['due_on' => '2026-11-24', 'amount_centimes' => 200000, 'opening_paid_centimes' => 50000]], 'opening_confirmed' => true, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.outgoing_centimes', 12500000)->assertJsonPath('summary.count', 2)->assertJsonPath('balances.payables.total_centimes', 7650000)->assertJsonPath('balances.payables.month_due_centimes', 0);
        $this->getJson('/api/v1/reports?vehicle_id='.$v->id)->assertOk()->assertJsonPath('vehicles.0.financing_centimes', 12500000);
        $this->getJson('/api/v1/reports/movements?category=purchase')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.outgoing_centimes', 5000000);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_current_debts_exclude_uncommitted_requests_and_pending_is_not_paid(): void
    {
        $a = $this->agency();
        [$r,$base] = $this->reservation($a, 'retournee', 70000);
        $this->receipt($base, 20000);
        $this->receipt($base, 30000, 'virement', false);
        $d = DB::table('reservation_departure_entries')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'action' => 'caution_received', 'amount_centimes' => 50000, 'method' => 'especes', 'received_on' => '2026-10-24', 'reason' => 'TEST', 'data' => '{}']);
        DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'deposit_allocate', 'scope' => 'rent', 'amount_centimes' => 10000, 'deposit_receipt_id' => $d, 'occurred_on' => '2026-10-24', 'reason' => 'TEST', 'snapshot' => '{}']);
        [$request,$reqbase] = $this->reservation($a);
        DB::table('reservation_quotes')->insert([...$reqbase, 'request_key' => (string) Str::uuid(), 'total_centimes' => 80000, 'reason' => 'Prix proposé TEST']);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.incoming_centimes', 20000)->assertJsonPath('balances.customers.remaining_centimes', 40000)->assertJsonPath('balances.customers.pending_centimes', 30000)->assertJsonPath('balances.customers.deposit_held_centimes', 40000)->assertJsonPath('balances.customers.count', 1);
        $this->getJson('/api/v1/reports?month=2026-09')->assertOk()->assertJsonPath('summary.incoming_centimes', 0)->assertJsonPath('balances.customers.remaining_centimes', 40000);
    }

    public function test_insurance_uses_approved_amount_and_voided_receipts_are_not_income(): void
    {
        [, $owner, , $vehicle, $otherVehicle] = $this->agency();
        $create = fn ($v) => $this->postJson('/api/v1/damages', ['kind' => 'create', 'vehicle_id' => $v->id, 'title' => 'Rayure TEST', 'zone' => 'Côté droit', 'description' => 'Rayure à examiner', 'occurred_local' => now('Africa/Casablanca')->subMinute()->format('Y-m-d\TH:i'), 'owner_membership_id' => $owner->id, 'follow_up_on' => '2026-10-24', 'next_action' => 'Vérifier assurance', 'reason' => 'Signalement TEST', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $id = $create($vehicle);
        $other = $create($otherVehicle);
        $action = function ($ticket, $kind, $values) {
            return $this->postJson('/api/v1/damages/'.$ticket, ['kind' => $kind, 'expected_version' => DB::table('damage_tickets')->where('id', $ticket)->value('version'), 'reason' => 'Vérification TEST justifiée', 'request_key' => (string) Str::uuid(), ...$values])->assertCreated();
        };
        $agreement = ['insurer' => 'Assureur TEST', 'reference' => 'REF123', 'requested_centimes' => 800000, 'approved_centimes' => null, 'evidence_reference' => 'Demande transmise'];
        $action($id, 'insurance', $agreement);
        $action($other, 'insurance', $agreement);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('balances.insurance.pending_count', 2)->assertJsonPath('balances.insurance.approved_remaining_centimes', 0);
        $action($id, 'insurance', [...$agreement, 'approved_centimes' => 600000]);
        $action($id, 'receipt', ['amount_centimes' => 200000, 'received_on' => '2026-10-24', 'method' => 'transfer', 'reference' => 'VERSEMENT1', 'checked' => true]);
        $this->getJson('/api/v1/reports?vehicle_id='.$vehicle->id)->assertOk()->assertJsonPath('summary.incoming_centimes', 200000)->assertJsonPath('balances.insurance.approved_remaining_centimes', 400000)->assertJsonPath('balances.customers.remaining_centimes', 0);
        $entry = DB::table('damage_entries')->where('ticket_id', $id)->where('kind', 'receipt')->value('id');
        $action($id, 'receipt_void', ['entry_id' => $entry]);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.incoming_centimes', 0)->assertJsonPath('balances.insurance.approved_remaining_centimes', 600000)->assertJsonPath('balances.insurance.pending_count', 1);
        $this->getJson('/api/v1/reports/movements?category=insurance')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_employee_and_foreign_company_cannot_access_reports(): void
    {
        [$a,$owner,$staff,$v] = $this->agency();
        $this->expense([['vehicle_id' => $v->id, 'amount_centimes' => 10000]], 10000);
        $this->agency();
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('summary.outgoing_centimes', 0);
        $this->getJson('/api/v1/reports?vehicle_id='.$v->id)->assertNotFound();
        $this->getJson('/api/v1/reports/movements?vehicle_id='.$v->id)->assertNotFound();
        $this->actingAs($staff->user)->withSession(['company_id' => $a->id]);
        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->getJson('/api/v1/reports/movements')->assertForbidden();
        $this->actingAs($owner->user)->withSession(['company_id' => null]);
        $this->getJson('/api/v1/reports')->assertForbidden();
    }
}
