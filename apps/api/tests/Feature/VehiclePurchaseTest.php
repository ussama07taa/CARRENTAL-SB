<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehiclePurchaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now('Africa/Casablanca')->setDate(2026, 10, 24)->startOfDay());
        $c = Company::factory()->create();
        [$m,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);

        return [$c, $m, $v];
    }

    private function input(int $vehicle): array
    {
        return ['vehicle_id' => $vehicle, 'lender' => 'Banque TEST', 'reference' => 'ACHAT-TEST',
            'tracking_from' => '2026-10-24', 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid(),
            'purchase' => ['seller' => 'Concession TEST', 'purchased_on' => '2026-10-24', 'down_payment_due_on' => '2026-10-24', 'price_centimes' => 20000000, 'down_payment_centimes' => 5000000],
            'installments' => [['due_on' => '2026-11-24', 'amount_centimes' => 7500000, 'opening_paid_centimes' => 0], ['due_on' => '2026-12-24', 'amount_centimes' => 7600000, 'opening_paid_centimes' => 0]],
        ];
    }

    private function payment(int $amount = 5000000, string $method = 'transfer', int $remaining = 5000000): array
    {
        return ['amount_centimes' => $amount, 'method' => $method, 'paid_on' => '2026-10-24',
            'expected_remaining_centimes' => $remaining, 'receipt_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    private function opening(): void
    {
        $this->postJson('/api/v1/cash/entries', ['kind' => 'opening', 'amount_centimes' => 10000000, 'occurred_on' => '2026-10-24',
            'reason' => 'TEST fictif', 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
    }

    public function test_purchase_and_schedule_create_no_cash_and_retries_create_one_dossier(): void
    {
        [,, $v] = $this->agency();
        $this->opening();
        $input = $this->input($v->id);
        $id = $this->postJson('/api/v1/loans', $input)->assertCreated()->json('id');
        $this->postJson('/api/v1/loans', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson("/api/v1/loans/$id")->assertOk()->assertJsonPath('data.financed_centimes', 15000000)
            ->assertJsonPath('data.remaining_centimes', 15100000)->assertJsonPath('purchase.data.paid_centimes', 0)
            ->assertJsonPath('purchase.data.remaining_centimes', 5000000)->assertJsonCount(0, 'purchase.payments');
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('cash.expected_centimes', 10000000)->assertJsonPath('total', 0);
        $this->getJson('/api/v1/expenses/payables?window=all')->assertOk()->assertJsonPath('total', 3)
            ->assertJsonPath('summary.expense_balance_centimes', 0)->assertJsonPath('summary.purchase_balance_centimes', 5000000)
            ->assertJsonPath('summary.loan_balance_centimes', 15100000)->assertJsonPath('summary.total_balance_centimes', 20100000);
        $this->getJson('/api/v1/expenses/payables?source=purchase')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/expenses/payables?source=expenses&window=all')->assertJsonPath('total', 0);
        $this->assertDatabaseCount('vehicle_purchases', 1);
        $this->assertDatabaseCount('vehicle_loan_installments', 2);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_apport_and_installment_are_distinct_flows_once_with_cash_and_bank_separated(): void
    {
        [,, $v] = $this->agency();
        $this->opening();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $p = $this->payment();
        $url = "/api/v1/loans/$id/purchase-payments";
        $pid = $this->postJson($url, $p)->assertCreated()->json('id');
        $this->postJson($url, $p)->assertCreated()->assertJsonPath('id', $pid);
        $this->postJson($url, $this->payment())->assertConflict();
        $d = $this->getJson("/api/v1/loans/$id")->assertJsonPath('purchase.data.remaining_centimes', 0)->json();
        $installment = $d['installments'][0]['id'];
        $this->postJson("/api/v1/loans/$id/installments/$installment/payments", $this->payment(7500000, 'cash', 7500000))->assertCreated();
        $this->getJson('/api/v1/cash')->assertOk()->assertJsonPath('cash.expected_centimes', 2500000)
            ->assertJsonPath('channels.bank.outgoing', 5000000)->assertJsonPath('channels.cash.outgoing', 7500000)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/cash?channel=noncash')->assertJsonPath('total', 1)->assertJsonPath('data.0.category', 'purchase');
        $this->getJson('/api/v1/expenses/payables?source=purchase')->assertJsonPath('total', 0);
        $this->assertDatabaseCount('vehicle_purchase_payments', 1);
        $this->assertDatabaseCount('vehicle_loan_payments', 1);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('expense_movements', 0);
    }

    public function test_correction_replaces_only_effective_cash_amount_and_keeps_original_and_stale_count(): void
    {
        [,, $v] = $this->agency();
        $this->opening();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $pid = $this->postJson("/api/v1/loans/$id/purchase-payments", $this->payment(5000000, 'cash'))->assertCreated()->json('id');
        $revision = $this->getJson('/api/v1/cash')->json('cash.revision');
        $this->postJson('/api/v1/cash/counts', ['counted_on' => '2026-10-24', 'counted_centimes' => 5000000, 'confirmed' => true,
            'expected_revision' => $revision, 'request_key' => (string) Str::uuid()])->assertCreated();
        $correction = [...$this->payment(4000000, 'transfer', 0), 'reason' => 'Montant et moyen corrigés après vérification', 'expected_version' => 1];
        $url = "/api/v1/loans/$id/purchase-payments/$pid";
        $cid = $this->postJson($url, $correction)->assertCreated()->json('id');
        $this->postJson($url, $correction)->assertCreated()->assertJsonPath('id', $cid);
        $this->postJson($url, [...$correction, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/v1/cash')->assertJsonPath('cash.expected_centimes', 10000000)->assertJsonPath('channels.bank.outgoing', 4000000)
            ->assertJsonPath('cash.previous_count.stale', true);
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('purchase.data.remaining_centimes', 1000000)
            ->assertJsonCount(1, 'purchase.payments')->assertJsonCount(2, 'purchase.history')->assertJsonPath('purchase.history.0.amount_centimes', 5000000);
        $this->postJson($url, [...$this->payment(0, 'transfer', 1000000), 'expected_version' => 2, 'reason' => 'Saisie fictive créée par erreur'])->assertCreated();
        $this->getJson('/api/v1/cash?channel=noncash')->assertJsonPath('total', 0);
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('purchase.data.remaining_centimes', 5000000)->assertJsonCount(3, 'purchase.history');
    }

    public function test_bad_purchase_and_duplicate_car_roll_back_loan_schedule_and_audit(): void
    {
        [,, $v] = $this->agency();
        $input = $this->input($v->id);
        foreach ([-1, 1.5, 20000000] as $bad) {
            $i = $input;
            $i['purchase']['down_payment_centimes'] = $bad;
            $this->postJson('/api/v1/loans', $i)->assertUnprocessable();
        }
        $this->postJson('/api/v1/loans', [...$input, 'financed_centimes' => 100])->assertUnprocessable();
        $i = $input;
        $i['installments'][0]['opening_paid_centimes'] = 1;
        $i['opening_confirmed'] = true;
        $this->postJson('/api/v1/loans', $i)->assertUnprocessable();
        $i = $input;
        $i['installments'][0]['amount_centimes'] = 100;
        $this->postJson('/api/v1/loans', $i)->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_loans', 0);
        $this->postJson('/api/v1/loans', $input)->assertCreated();
        $this->postJson('/api/v1/loans', [...$input, 'reference' => 'ANOTHER', 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->assertDatabaseCount('vehicle_loans', 1);
        $this->assertDatabaseCount('vehicle_purchases', 1);
        $this->assertDatabaseCount('vehicle_loan_installments', 2);
    }

    public function test_invalid_overpaid_future_and_unconfirmed_apports_are_rejected(): void
    {
        [,, $v] = $this->agency();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $url = "/api/v1/loans/$id/purchase-payments";
        foreach ([0, -1, 100.25, 5000001] as $bad) {
            $this->postJson($url, [...$this->payment(), 'amount_centimes' => $bad])->assertUnprocessable();
        }
        $this->postJson($url, [...$this->payment(), 'paid_on' => '2026-10-23'])->assertUnprocessable();
        $this->postJson($url, [...$this->payment(), 'paid_on' => '2026-10-25'])->assertUnprocessable();
        $this->postJson($url, [...$this->payment(), 'receipt_confirmed' => false])->assertUnprocessable();
        $this->postJson($url, [...$this->payment(), 'company_id' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_purchase_payments', 0);
    }

    public function test_employee_other_tenant_and_missing_tenant_cannot_access_purchase_money(): void
    {
        [$c,$m,$v] = $this->agency();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $pid = $this->postJson("/api/v1/loans/$id/purchase-payments", $this->payment())->assertCreated()->json('id');
        $employee = Tenant::run($c->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->withSession(['company_id' => $c->id]);
        $this->getJson("/api/v1/loans/$id")->assertForbidden();
        $this->postJson("/api/v1/loans/$id/purchase-payments", $this->payment())->assertForbidden();
        $this->postJson("/api/v1/loans/$id/purchase-payments/$pid", $this->payment())->assertForbidden();
        $other = Company::factory()->create();
        $owner = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($owner->user)->withSession(['company_id' => $other->id]);
        $this->getJson("/api/v1/loans/$id")->assertNotFound();
        $this->postJson("/api/v1/loans/$id/purchase-payments/$pid", $this->payment())->assertNotFound();
        $this->getJson('/api/v1/expenses/payables?source=purchase')->assertJsonPath('total', 0);
        $this->actingAs($m->user)->withSession(['company_id' => null]);
        $this->postJson("/api/v1/loans/$id/purchase-payments", $this->payment())->assertForbidden();
    }

    public function test_purchase_history_and_tenant_foreign_keys_are_protected_in_postgresql(): void
    {
        [$c,,$v] = $this->agency();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $this->postJson("/api/v1/loans/$id/purchase-payments", $this->payment())->assertCreated();
        foreach (['vehicle_purchases', 'vehicle_purchase_payments'] as $table) {
            foreach (['update', 'delete', 'truncate'] as $operation) {
                $rejected = false;
                try {
                    DB::transaction(function () use ($table, $operation) {
                        if ($operation === 'update') {
                            DB::table($table)->update(['company_id' => 999]);
                        } elseif ($operation === 'delete') {
                            DB::table($table)->delete();
                        } else {
                            DB::statement("TRUNCATE $table CASCADE");
                        }
                    });
                } catch (QueryException) {
                    $rejected = true;
                }
                $this->assertTrue($rejected, "$operation $table must fail");
            }
        }
        $other = Company::factory()->create();
        $p = (array) DB::table('vehicle_purchase_payments')->first();
        unset($p['id']);
        $p['company_id'] = $other->id;
        $p['request_key'] = (string) Str::uuid();
        $p['payment_key'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('vehicle_purchase_payments')->insert($p));
    }

    public function test_actual_apport_prevents_cancelling_its_purchase_contract(): void
    {
        [,, $v] = $this->agency();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $this->postJson("/api/v1/loans/$id/purchase-payments", $this->payment())->assertCreated();
        $detail = $this->getJson("/api/v1/loans/$id")->json();
        $this->postJson("/api/v1/loans/$id/cancellations", ['reason' => 'Erreur TEST', 'confirmed' => true,
            'expected_loan_remaining_centimes' => 15100000, 'financial_revision' => $detail['financial_revision'], 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_loan_cancellations', 0);
    }
}
