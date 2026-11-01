<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now('Africa/Casablanca')->setDate(2026, 10, 24)->startOfDay());
        $c = Company::factory()->create();
        [$m,$s,$v,$client] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create(), Vehicle::factory()->create(), Customer::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);

        return [$c, $m, $s, $v, $client];
    }

    private function input(string $kind = 'opening', int $amount = 100000, string $date = '2026-10-24'): array
    {
        return ['kind' => $kind, 'amount_centimes' => $amount, 'occurred_on' => $date, 'reason' => 'Fonds fictifs vérifiés', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    private function open(): int
    {
        return $this->postJson('/api/v1/cash/entries', $this->input())->assertCreated()->json('id');
    }

    private function countInput(int $amount): array
    {
        return ['counted_on' => '2026-10-24', 'counted_centimes' => $amount, 'note' => 'Comptage TEST', 'confirmed' => true, 'expected_revision' => $this->getJson('/api/v1/cash')->assertOk()->json('cash.revision'), 'request_key' => (string) Str::uuid()];
    }

    private function expense(int $amount = 30000, string $method = 'cash', string $date = '2026-10-24'): int
    {
        return $this->postJson('/api/v1/expenses', ['title' => 'Lavage TEST', 'category' => 'wash', 'supplier' => 'Station TEST', 'invoice_reference' => (string) Str::uuid(), 'incurred_on' => $date, 'amount_centimes' => $amount, 'allocations' => [['vehicle_id' => null, 'amount_centimes' => $amount]], 'paid_centimes' => $amount, 'paid_on' => $date, 'method' => $method, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function reservation(array $a): array
    {
        [$c,$m,,$v,$client] = $a;
        $base = ['company_id' => $c->id, 'actor_membership_id' => $m->id, 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_at' => now()];
        $id = DB::table('reservations')->insertGetId([...$base, 'vehicle_id' => $v->id, 'customer_id' => $client->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);

        return [$id, [...$base, 'reservation_id' => $id]];
    }

    public function test_opening_is_explicit_and_count_is_a_snapshot_not_an_expense(): void
    {
        $this->agency();
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('cash.expected_centimes', null);
        $opening = $this->open();
        $expense = $this->expense();
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('channels.cash.outgoing', 30000)->assertJsonPath('cash.expected_centimes', 70000)->assertJsonPath('total', 1);
        $input = $this->countInput(65000);
        $id = $this->postJson('/api/v1/cash/counts', $input)->assertCreated()->json('id');
        $this->postJson('/api/v1/cash/counts', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.previous_count.difference_centimes', -5000)->assertJsonPath('cash.previous_count.stale', false)->assertJsonPath('cash.expected_centimes', 70000);
        $this->assertDatabaseCount('cash_counts', 1);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_movements', 1);
        $this->postJson('/api/v1/cash/entries', $this->input())->assertConflict();
    }

    public function test_late_entries_and_corrections_invalidate_comparison_without_rewriting_it(): void
    {
        $this->agency();
        $id = $this->open();
        $before = $this->countInput(100000);
        $this->postJson('/api/v1/cash/counts', $before)->assertCreated();
        $stale = $this->countInput(100000);
        $this->expense();
        $this->postJson('/api/v1/cash/counts', $stale)->assertConflict();
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.previous_count.stale', true)->assertJsonPath('cash.previous_count.expected_centimes', 100000)->assertJsonPath('cash.expected_centimes', 70000);
        $this->postJson('/api/v1/cash/entries/'.$id, [...$this->input('opening', 120000), 'expected_version' => 1])->assertCreated();
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.expected_centimes', 90000)->assertJsonPath('cash.opening.version', 2);
        $this->postJson('/api/v1/cash/entries/'.$id, [...$this->input('opening', 130000), 'expected_version' => 1])->assertConflict();
        $this->getJson('/api/v1/cash/entries/'.$id)->assertJsonCount(2, 'history');
    }

    public function test_bank_transfer_is_two_sides_of_one_operation_and_retry_does_not_duplicate(): void
    {
        $this->agency();
        $this->open();
        $input = $this->input('bank_deposit', 40000);
        $id = $this->postJson('/api/v1/cash/entries', $input)->assertCreated()->json('id');
        $this->postJson('/api/v1/cash/entries', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.expected_centimes', 60000)->assertJsonPath('channels.cash.outgoing', 40000)->assertJsonPath('channels.bank.incoming', 40000)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/cash?channel=noncash')->assertJsonPath('total', 1)->assertJsonPath('data.0.amount_centimes', 40000);
        $this->postJson('/api/v1/cash/entries/'.$id, [...$this->input('bank_deposit', 0), 'expected_version' => 1])->assertCreated();
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.expected_centimes', 100000)->assertJsonPath('total', 0)->assertJsonPath('manual_history.0.amount_centimes', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_pending_transfers_and_card_holds_do_not_inflate_cash_and_allocations_are_not_new_receipts(): void
    {
        $a = $this->agency();
        $this->open();
        [$r,$base] = $this->reservation($a);
        foreach ([['especes', 10000, true], ['virement', 20000, false], ['carte', 30000, true]] as [$method,$amount,$verified]) {
            $id = DB::table('rental_payments')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'method' => $method, 'amount_centimes' => $amount, 'received_on' => '2026-10-24']);
            if ($verified) {
                DB::table('rental_payment_verifications')->insert([...$base, 'request_key' => (string) Str::uuid(), 'payment_id' => $id, 'reason' => 'TEST']);
            }
        }
        foreach ([['especes', 50000], ['carte_bloquee', 90000]] as [$method,$amount]) {
            $id = DB::table('reservation_departure_entries')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'action' => 'caution_received', 'amount_centimes' => $amount, 'method' => $method, 'received_on' => '2026-10-24', 'reason' => 'TEST', 'data' => '{}']);
            if ($method === 'especes') {
                DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'deposit_allocate', 'scope' => 'rent', 'amount_centimes' => 10000, 'deposit_receipt_id' => $id, 'occurred_on' => '2026-10-24', 'reason' => 'TEST', 'snapshot' => '{}']);
                DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'deposit_return', 'scope' => 'deposit', 'amount_centimes' => 20000, 'method' => 'especes', 'deposit_receipt_id' => $id, 'occurred_on' => '2026-10-24', 'reason' => 'TEST', 'snapshot' => '{}']);
            }
        }
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('cash.expected_centimes', 140000)->assertJsonPath('pending_centimes', 20000)->assertJsonPath('blocked_count', 1)->assertJsonPath('channels.card.incoming', 30000)->assertJsonPath('channels.cash.incoming', 60000)->assertJsonPath('channels.cash.outgoing', 20000)->assertJsonPath('total', 3);
    }

    public function test_loan_opening_history_is_not_a_new_payment_and_installment_date_is_not_the_cash_date(): void
    {
        [,,,$v] = $this->agency();
        $this->open();
        $loan = $this->postJson('/api/v1/loans', ['vehicle_id' => $v->id, 'lender' => 'Banque TEST', 'reference' => 'CR-TEST', 'tracking_from' => '2026-10-01', 'installments' => [['due_on' => '2026-11-24', 'amount_centimes' => 200000, 'opening_paid_centimes' => 50000]], 'opening_confirmed' => true, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $installment = $this->getJson('/api/v1/loans/'.$loan)->json('installments.0.id');
        $this->postJson("/api/v1/loans/$loan/installments/$installment/payments", ['amount_centimes' => 150000, 'paid_on' => '2026-10-24', 'method' => 'transfer', 'expected_remaining_centimes' => 150000, 'receipt_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/cash?channel=noncash')->assertJsonPath('channels.bank.outgoing', 150000)->assertJsonPath('cash.expected_centimes', 100000)->assertJsonPath('data.0.source', 'loan')->assertJsonPath('total', 1);
    }

    public function test_tenant_scope_and_staff_cannot_read_or_write_cash(): void
    {
        [$a,$owner,$staff] = $this->agency();
        $id = $this->open();
        [$b,$other] = $this->agency();
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.expected_centimes', null);
        $this->getJson('/api/v1/cash/entries/'.$id)->assertNotFound();
        $this->actingAs($staff->user)->withSession(['company_id' => $a->id]);
        $this->getJson('/api/v1/cash')->assertForbidden();
        $this->postJson('/api/v1/cash/entries', $this->input())->assertForbidden();
        $this->actingAs($owner->user)->withSession(['company_id' => null]);
        $this->getJson('/api/v1/cash')->assertForbidden();
    }

    public function test_dates_search_direction_and_sql_pagination_do_not_change_the_balance(): void
    {
        $this->agency();
        $this->open();
        for ($i = 0; $i < 22; $i++) {
            $this->postJson('/api/v1/cash/entries', $this->input('cash_in', 100))->assertCreated();
        }
        $this->expense(5000);
        DB::enableQueryLog();
        $this->getJson('/api/v1/cash?page=2')->assertJsonPath('total', 23)->assertJsonCount(3, 'data')->assertJsonPath('cash.expected_centimes', 97200);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringContainsString('limit 20 offset 20', $sql);
        $this->getJson('/api/v1/cash?q=Station&direction=out')->assertJsonPath('total', 1)->assertJsonPath('cash.expected_centimes', 97200);
        $this->getJson('/api/v1/cash?from=2026-10-25&to=2026-10-24')->assertUnprocessable();
        $this->postJson('/api/v1/cash/entries', $this->input('cash_in', 100, '2026-10-25'))->assertUnprocessable();
    }

    public function test_difference_requires_note_and_never_becomes_a_fake_expense(): void
    {
        $this->agency();
        $this->open();
        $input = $this->countInput(99900);
        $input['note'] = '';
        $this->postJson('/api/v1/cash/counts', $input)->assertUnprocessable();
        $input['note'] = 'Manque constaté de 1 DH';
        $this->postJson('/api/v1/cash/counts', $input)->assertCreated();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_cash_period_starts_at_opening_but_bank_history_stays_visible(): void
    {
        $this->agency();
        $this->expense(10000, 'cash', '2026-10-22');
        $this->expense(20000, 'transfer', '2026-10-22');
        $this->open();
        $this->expense(30000);
        $this->getJson('/api/v1/cash?from=2026-10-01&to=2026-10-24')->assertOk()->assertJsonPath('channels.cash.outgoing', 30000)->assertJsonPath('channels.bank.outgoing', 20000)->assertJsonPath('cash.effective_from', '2026-10-24')->assertJsonPath('cash.period_opening_centimes', 100000)->assertJsonPath('cash.expected_centimes', 70000)->assertJsonPath('total', 1);
    }

    public function test_maintenance_payment_and_refund_are_counted_once_after_amount_correction(): void
    {
        [,,,$v] = $this->agency();
        $this->postJson('/api/v1/cash/entries', $this->input('opening', 100000, '2026-10-20'))->assertCreated();
        $base = "/api/v1/vehicles/$v->id/maintenance";
        $input = ['mode' => 'completed', 'performed_on' => '2026-10-20', 'garage' => 'Garage ONCE', 'items' => [['kind' => 'oil', 'title' => 'Vidange', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000, 'completed_on' => '2026-10-20']], 'paid_centimes' => 65000, 'paid_on' => '2026-10-20', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($base.'/files', $input)->assertCreated()->json('id');
        $this->postJson($base.'/files', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/cash?from=2026-10-20')->assertJsonPath('cash.expected_centimes', 35000)->assertJsonPath('total', 1);
        $file = $this->getJson("$base/$id/file")->json();
        $d = $file['data'];
        $pid = $file['payments'][0]['id'];
        $this->postJson("$base/$id/payments/$pid/corrections", ['amount_centimes' => 60000, 'reason' => 'Correction du montant saisi', 'correction_revision' => 0, 'work_revision' => $d['revision'], 'expected_paid_centimes' => 65000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $d = $this->getJson("$base/$id/file")->json('data');
        $this->postJson("$base/$id/refunds", ['amount_centimes' => 60000, 'received_on' => '2026-10-21', 'method' => 'cash', 'reason' => 'Versement retourné intégralement', 'work_revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => $d['paid_centimes'], 'expected_refunded_centimes' => $d['refunded_centimes'], 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/cash?from=2026-10-20')->assertOk()->assertJsonPath('cash.expected_centimes', 100000)->assertJsonPath('channels.cash.outgoing', 60000)->assertJsonPath('channels.cash.incoming', 60000)->assertJsonPath('total', 2);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('maintenance_payments', 1);
    }

    public function test_dates_before_opening_allow_history_but_not_a_second_opening_or_count(): void
    {
        $this->agency();
        $this->open();
        $this->getJson('/api/v1/cash?from=2026-10-22&to=2026-10-22')->assertOk()->assertJsonPath('cash.expected_centimes', null)->assertJsonPath('cash.opening.date', '2026-10-24');
        $this->postJson('/api/v1/cash/counts', [...$this->countInput(100000), 'counted_on' => '2026-10-22'])->assertUnprocessable();
    }

    public function test_immutable_cash_history_is_protected_by_postgres(): void
    {
        $this->agency();
        $id = $this->open();
        try {
            DB::transaction(fn () => DB::table('cash_entries')->where('id', $id)->update(['amount_centimes' => 1]));
            $this->fail('An opening must not be rewritten.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.expected_centimes', 100000);
    }

    public function test_shared_invoice_counts_one_payment_and_latest_correction_with_actual_refund(): void
    {
        [$c,,,$v] = $this->agency();
        $this->open();
        $v2 = Tenant::run($c->id, fn () => Vehicle::factory()->create());
        $id = $this->postJson('/api/v1/expenses', ['title' => 'Deux voitures', 'category' => 'wash', 'supplier' => 'Station TEST', 'incurred_on' => '2026-10-24', 'amount_centimes' => 30000, 'allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 10000], ['vehicle_id' => $v2->id, 'amount_centimes' => 20000]], 'paid_centimes' => 30000, 'paid_on' => '2026-10-24', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $d = $this->getJson('/api/v1/expenses/'.$id)->json('data');
        $input = ['kind' => 'payment', 'movement_key' => $d['movements'][0]['movement_key'], 'amount_centimes' => 25000, 'occurred_on' => '2026-10-24', 'method' => 'cash', 'reason' => 'Correction du paiement saisi', 'confirmed' => true, 'expected_revision' => $d['revision'], 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/expenses/$id/movements", $input)->assertCreated();
        $d = $this->getJson('/api/v1/expenses/'.$id)->json('data');
        unset($input['movement_key']);
        $input['kind'] = 'refund';
        $input['amount_centimes'] = 5000;
        $input['expected_revision'] = $d['revision'];
        $input['request_key'] = (string) Str::uuid();
        $this->postJson("/api/v1/expenses/$id/movements", $input)->assertCreated();
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('channels.cash.outgoing', 25000)->assertJsonPath('channels.cash.incoming', 5000)->assertJsonPath('cash.expected_centimes', 80000);
    }

    public function test_void_rent_and_refund_approval_are_not_cash_but_executed_refund_is(): void
    {
        $a = $this->agency();
        $this->open();
        [$r,$base] = $this->reservation($a);
        $id = DB::table('rental_payments')->insertGetId([...$base, 'method' => 'especes', 'amount_centimes' => 30000, 'received_on' => '2026-10-24']);
        DB::table('rental_payment_verifications')->insert([...$base, 'request_key' => (string) Str::uuid(), 'payment_id' => $id, 'reason' => 'TEST']);
        DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'payment_void', 'scope' => 'rent', 'amount_centimes' => 30000, 'rent_payment_id' => $id, 'occurred_on' => '2026-10-24', 'reason' => 'Saisie erronée', 'snapshot' => '{}']);
        $approval = DB::table('rental_settlement_entries')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'refund_approved', 'scope' => 'rent', 'amount_centimes' => 5000, 'occurred_on' => '2026-10-24', 'reason' => 'TEST approbation', 'snapshot' => '{}']);
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('cash.expected_centimes', 100000);
        DB::table('rental_settlement_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'refund_paid', 'scope' => 'rent', 'source_id' => $approval, 'method' => 'especes', 'amount_centimes' => 5000, 'occurred_on' => '2026-10-24', 'reason' => 'TEST exécution', 'snapshot' => '{}']);
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('channels.cash.outgoing', 5000)->assertJsonPath('cash.expected_centimes', 95000);
    }

    public function test_insurer_decision_is_not_cash_and_void_receipt_is_excluded(): void
    {
        [$c,$m,,$v] = $this->agency();
        $this->open();
        $ticket = DB::table('damage_tickets')->insertGetId(['company_id' => $c->id, 'vehicle_id' => $v->id, 'owner_membership_id' => $m->id, 'title' => 'TEST sinistre', 'zone' => 'Avant', 'description' => 'Dommage fictif', 'occurred_at' => now(), 'follow_up_on' => '2026-10-24', 'next_action' => 'Suivi TEST', 'created_at' => now(), 'updated_at' => now()]);
        $base = ['company_id' => $c->id, 'ticket_id' => $ticket, 'actor_membership_id' => $m->id, 'request_hash' => str_repeat('a', 64), 'created_at' => now(), 'reason' => 'TEST'];
        DB::table('damage_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'insurance', 'payload' => json_encode(['requested_centimes' => 90000, 'approved_centimes' => 80000])]);
        $id = DB::table('damage_entries')->insertGetId([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'receipt', 'payload' => json_encode(['amount_centimes' => 20000, 'received_on' => '2026-10-24', 'method' => 'transfer', 'checked' => true])]);
        $this->getJson('/api/v1/cash?channel=noncash')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('channels.bank.incoming', 20000)->assertJsonPath('cash.expected_centimes', 100000);
        DB::table('damage_entries')->insert([...$base, 'request_key' => (string) Str::uuid(), 'kind' => 'receipt_void', 'payload' => json_encode(['entry_id' => $id])]);
        $this->getJson('/api/v1/cash?channel=noncash')->assertOk()->assertJsonPath('total', 0);
    }
}
