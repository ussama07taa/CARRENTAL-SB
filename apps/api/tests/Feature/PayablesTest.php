<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayablesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now('Africa/Casablanca')->setDate(2026, 10, 24)->startOfDay());
        $company = Company::factory()->create();
        [$owner,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($owner->user)->withSession(['company_id' => $company->id]);

        return [$company, $owner, $vehicle];
    }

    private function loan(Vehicle $vehicle, ?array $rows = null): int
    {
        return $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Banque DEMO', 'reference' => (string) Str::uuid(), 'signed_on' => '2026-01-01', 'tracking_from' => '2026-10-01', 'installments' => $rows ?? [['due_on' => '2026-10-25', 'amount_centimes' => 300000, 'opening_paid_centimes' => 100000], ['due_on' => '2026-12-25', 'amount_centimes' => 300000, 'opening_paid_centimes' => 0]], 'opening_confirmed' => true, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function expense(int $amount = 50000, ?string $due = null, array $allocations = []): int
    {
        return $this->postJson('/api/v1/expenses', ['title' => 'Facture TEST', 'category' => 'wash', 'supplier' => 'Station TEST', 'invoice_reference' => (string) Str::uuid(), 'incurred_on' => '2026-10-20', 'due_on' => $due, 'amount_centimes' => $amount, 'allocations' => $allocations ?: [['vehicle_id' => null, 'amount_centimes' => $amount]], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function payment(int $remaining, int $amount): array
    {
        return ['amount_centimes' => $amount, 'paid_on' => '2026-10-24', 'method' => 'transfer', 'expected_remaining_centimes' => $remaining, 'receipt_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_payment_from_payables_updates_only_the_original_installment_and_excludes_historical_settlement(): void
    {
        [,, $car] = $this->agency();
        $loan = $this->loan($car);
        $this->expense();
        $list = $this->getJson('/api/v1/expenses/payables')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.expense_balance_centimes', 50000)->assertJsonPath('summary.loan_balance_centimes', 200000)->assertJsonPath('summary.total_balance_centimes', 250000);
        $row = collect($list->json('data'))->firstWhere('source', 'loan');
        $this->assertSame(100000, $row['opening_paid_centimes']);
        $this->assertSame(0, $row['paid_centimes']);
        $url = "/api/v1/loans/$loan/installments/{$row['installment_id']}/payments";
        $input = $this->payment(200000, 50000);
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson($url, $this->payment(200000, 50000))->assertConflict();
        $this->getJson('/api/v1/expenses/payables')->assertJsonPath('summary.loan_balance_centimes', 150000)->assertJsonPath('summary.total_balance_centimes', 200000);
        $this->postJson($url, $this->payment(150000, 150000))->assertCreated();
        $this->getJson('/api/v1/expenses/payables')->assertJsonPath('total', 1)->assertJsonPath('summary.loan_balance_centimes', 0);
        $this->getJson('/api/v1/expenses/payables?window=all&source=loan')->assertJsonPath('total', 1)->assertJsonPath('summary.loan_balance_centimes', 300000);
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.remaining_centimes', 300000)->assertJsonPath('data.recorded_paid_centimes', 200000)->assertJsonPath('data.opening_paid_centimes', 100000);
        $this->getJson('/api/v1/expenses')->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 50000)->assertJsonPath('summary.paid_centimes', 0);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_movements', 0);
        $this->assertDatabaseCount('vehicle_loan_payments', 2);
    }

    public function test_horizon_uses_due_dates_including_overdue_and_undated_expenses_with_exact_boundaries(): void
    {
        [,, $car] = $this->agency();
        $rows = [];
        foreach (['2026-10-23', '2026-10-24', '2026-11-23', '2026-11-24'] as $date) {
            $rows[] = ['due_on' => $date, 'amount_centimes' => 10000, 'opening_paid_centimes' => 0];
        }
        $this->loan($car, $rows);
        $this->expense(1000);
        $this->expense(2000, '2026-10-22');
        $this->expense(4000, '2026-12-01');
        $r = $this->getJson('/api/v1/expenses/payables')->assertOk()->assertJsonPath('total', 5)->assertJsonPath('summary.expense_balance_centimes', 3000)->assertJsonPath('summary.loan_balance_centimes', 30000)->assertJsonPath('summary.undated_count', 1)->assertJsonPath('until', '2026-11-23');
        $this->assertSame(['overdue', 'overdue', 'due', 'upcoming', 'undated'], array_column($r->json('data'), 'state'));
        $this->getJson('/api/v1/expenses/payables?window=overdue')->assertJsonPath('total', 2)->assertJsonPath('summary.total_balance_centimes', 12000)->assertJsonPath('summary.undated_count', 0);
        $this->getJson('/api/v1/expenses/payables?window=all')->assertJsonPath('total', 7)->assertJsonPath('summary.total_balance_centimes', 47000);
        $this->getJson('/api/v1/expenses/payables?source=expenses')->assertJsonPath('summary.loan_balance_centimes', 0)->assertJsonPath('summary.total_balance_centimes', 3000);
        $this->getJson('/api/v1/expenses/payables?window=wrong')->assertUnprocessable();
    }

    public function test_car_filter_shows_the_whole_shared_invoice_balance_once_and_the_correct_loan(): void
    {
        [$c,, $car] = $this->agency();
        $other = Tenant::run($c->id, fn () => Vehicle::factory()->create());
        $this->expense(120000, null, [['vehicle_id' => $car->id, 'amount_centimes' => 90000], ['vehicle_id' => $other->id, 'amount_centimes' => 30000]]);
        $this->loan($car);
        $this->loan($other);
        $this->getJson('/api/v1/expenses/payables?vehicle_id='.$car->id)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.expense_balance_centimes', 120000)->assertJsonPath('summary.loan_balance_centimes', 200000);
        $this->getJson('/api/v1/expenses/payables?q='.urlencode($car->plate))->assertJsonPath('total', 2);
        $this->getJson('/api/v1/expenses/payables')->assertJsonPath('total', 3)->assertJsonPath('summary.expense_balance_centimes', 120000);
    }

    public function test_payment_corrections_and_cancellations_are_reflected_without_duplicate_rows(): void
    {
        [,, $car] = $this->agency();
        $loan = $this->loan($car);
        $row = $this->getJson('/api/v1/expenses/payables?source=loan')->assertOk()->json('data.0');
        $payment = $this->postJson("/api/v1/loans/$loan/installments/{$row['installment_id']}/payments", $this->payment(200000, 50000))->assertCreated()->json('id');
        $file = $this->getJson("/api/v1/loans/$loan")->json();
        $common = ['reason' => 'Erreur de saisie TEST', 'correction_revision' => 0, 'financial_revision' => $file['financial_revision'], 'expected_remaining_centimes' => 150000, 'expected_loan_remaining_centimes' => 450000, 'confirmed' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/loans/$loan/payments/$payment/corrections", [...$common, 'amount_centimes' => 30000])->assertCreated();
        $this->getJson('/api/v1/expenses/payables?source=loan')->assertJsonPath('total', 1)->assertJsonPath('data.0.paid_centimes', 30000)->assertJsonPath('summary.loan_balance_centimes', 170000);
        $file = $this->getJson("/api/v1/loans/$loan")->json();
        $this->postJson("/api/v1/loans/$loan/payments/$payment/cancellations", [...$common, 'correction_revision' => 1, 'financial_revision' => $file['financial_revision'], 'expected_remaining_centimes' => 170000, 'expected_loan_remaining_centimes' => 470000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/expenses/payables?source=loan')->assertJsonPath('total', 1)->assertJsonPath('data.0.paid_centimes', 0)->assertJsonPath('summary.loan_balance_centimes', 200000);
        $this->assertDatabaseCount('vehicle_loan_payments', 1);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_cancelled_contracts_and_cancelled_expenses_are_not_payable(): void
    {
        [,, $car] = $this->agency();
        $loan = $this->loan($car, [['due_on' => '2026-10-25', 'amount_centimes' => 500000, 'opening_paid_centimes' => 0]]);
        $expense = $this->expense();
        $file = $this->getJson("/api/v1/loans/$loan")->json();
        $this->postJson("/api/v1/loans/$loan/cancellations", ['reason' => 'Contrat TEST saisi par erreur', 'financial_revision' => $file['financial_revision'], 'expected_loan_remaining_centimes' => 500000, 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $entry = $this->getJson('/api/v1/expenses/'.$expense)->json('data');
        $this->postJson('/api/v1/expenses/'.$expense.'/cancel', ['reason' => 'Facture TEST saisie par erreur', 'expected_revision' => $entry['revision'], 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/expenses/payables?window=all')->assertJsonPath('total', 0)->assertJsonPath('summary.total_balance_centimes', 0);
    }

    public function test_staff_tenant_and_missing_context_do_not_expose_financial_data(): void
    {
        [$a,$owner,$car] = $this->agency();
        $this->loan($car);
        $this->expense();
        [$b,$otherOwner,$otherCar] = $this->agency();
        $this->loan($otherCar);
        $this->actingAs($owner->user)->withSession(['company_id' => $a->id]);
        $this->getJson('/api/v1/expenses/payables')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/expenses/payables?vehicle_id='.$otherCar->id)->assertOk()->assertJsonPath('total', 0);
        $staff = Tenant::run($a->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($staff->user)->withSession(['company_id' => $a->id]);
        $this->getJson('/api/v1/expenses/payables')->assertForbidden();
        $this->actingAs($owner->user)->withSession(['company_id' => null]);
        $this->getJson('/api/v1/expenses/payables')->assertForbidden();
    }

    public function test_maintenance_is_read_from_its_source_and_unknown_cost_is_not_an_invented_bill(): void
    {
        [,, $car] = $this->agency();
        foreach (['confirmed', 'estimate', 'unknown'] as $status) {
            $this->postJson('/api/v1/vehicles/'.$car->id.'/maintenance/files', ['mode' => 'completed', 'performed_on' => '2026-10-24', 'garage' => 'Garage '.$status, 'paid_centimes' => 0, 'items' => [['kind' => 'oil', 'title' => 'Vidange TEST', 'status' => 'done', 'cost_status' => $status, 'amount_centimes' => $status === 'unknown' ? null : 60000]], 'request_key' => (string) Str::uuid()])->assertCreated();
        }
        $this->getJson('/api/v1/expenses/payables')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('summary.provisional_count', 1)->assertJsonPath('summary.expense_balance_centimes', 120000);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_pagination_is_applied_to_the_combined_sources_and_keeps_complete_totals(): void
    {
        [,, $car] = $this->agency();
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = ['due_on' => '2026-10-25', 'amount_centimes' => 10000, 'opening_paid_centimes' => 0];
        }
        $this->loan($car, $rows);
        $this->expense(50000);
        DB::enableQueryLog();
        $this->getJson('/api/v1/expenses/payables?page=2')->assertOk()->assertJsonPath('total', 26)->assertJsonCount(6, 'data')->assertJsonPath('summary.total_balance_centimes', 300000);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringContainsString('limit 20 offset 20', $queries);
        $this->assertStringNotContainsString('vehicle_loan_documents', $queries);
    }
}
