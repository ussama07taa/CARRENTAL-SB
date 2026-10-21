<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleLoans;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleLoanPaymentCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $paid = 100000, int $opening = 0): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $company = Company::factory()->create();
        [$member,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Banque fictive', 'reference' => 'CORRECTION', 'tracking_from' => '2026-10-01', 'schedule_confirmed' => true, 'opening_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [['due_on' => '2026-10-05', 'amount_centimes' => 250000, 'opening_paid_centimes' => $opening], ['due_on' => '2026-11-05', 'amount_centimes' => 250000, 'opening_paid_centimes' => 0]]])->assertCreated()->json('id');
        $row = $this->getJson("/api/v1/loans/$id")->json('installments.0.id');
        $payment = $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => $paid, 'paid_on' => '2026-10-17', 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 250000 - $opening, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');

        return [$id, $payment, $row, $company, $member];
    }

    private function input(int $loan, int $payment, int $amount = 10000): array
    {
        $d = $this->getJson("/api/v1/loans/$loan")->assertOk()->json();
        $p = collect($d['payments'])->firstWhere('id', $payment);
        $i = collect($d['installments'])->firstWhere('id', $p['installment_id']);

        return ['amount_centimes' => $amount, 'reason' => '1000 saisis au lieu de 100', 'confirmed' => true, 'correction_revision' => $p['correction_revision'], 'financial_revision' => $d['financial_revision'], 'expected_remaining_centimes' => $i['remaining_centimes'], 'expected_loan_remaining_centimes' => $d['data']['remaining_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_1000_corrected_to_100_keeps_original_history_and_updates_all_balances_once(): void
    {
        [$id,$payment,$row,,$member] = $this->fixture();
        $original = DB::table('vehicle_loan_payments')->get()->toJson();
        $input = $this->input($id, $payment);
        $url = "/api/v1/loans/$id/payments/$payment/corrections";
        $cid = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $cid);
        $this->postJson($url, [...$input, 'amount_centimes' => 20000])->assertConflict();
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.recorded_paid_centimes', 10000)->assertJsonPath('data.remaining_centimes', 490000)->assertJsonPath('installments.0.remaining_centimes', 240000)->assertJsonPath('payments.0.amount_centimes', 100000)->assertJsonPath('payments.0.effective_amount_centimes', 10000)->assertJsonPath('payments.0.correction_revision', 1)->assertJsonPath('corrections.0.before_centimes', 100000)->assertJsonPath('corrections.0.amount_centimes', 10000)->assertJsonPath('corrections.0.reason', $input['reason'])->assertJsonPath('corrections.0.actor', $member->user->name)->assertJsonCount(1, 'payments');
        $this->getJson('/api/v1/loans?filter=overdue')->assertJsonPath('summary.month_centimes', 240000)->assertJsonPath('summary.recorded_paid_centimes', 10000)->assertJsonPath('summary.remaining_centimes', 490000);
        $this->assertSame($original, DB::table('vehicle_loan_payments')->get()->toJson());
        $this->assertDatabaseCount('vehicle_loan_payment_corrections', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'loan_payment_corrected', 'actor_membership_id' => $member->id]);
        $this->postJson($url, $this->input($id, $payment, 20025))->assertCreated();
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('installments.0.remaining_centimes', 229975)->assertJsonPath('payments.0.effective_amount_centimes', 20025)->assertJsonPath('corrections.1.before_centimes', 10000)->assertJsonPath('corrections.1.amount_centimes', 20025)->assertJsonCount(2, 'corrections');
    }

    public function test_correction_reopens_settled_installment_preserving_opening_balance_and_subsequent_payment_rules(): void
    {
        [$id,$payment,$row] = $this->fixture(100000, 150000);
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.next_due_on', '2026-11-05');
        $this->postJson("/api/v1/loans/$id/payments/$payment/corrections", $this->input($id, $payment))->assertCreated();
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.next_due_on', '2026-10-05')->assertJsonPath('data.opening_paid_centimes', 150000)->assertJsonPath('installments.0.status', 'overdue')->assertJsonPath('installments.0.remaining_centimes', 90000);
        $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => 90000, 'paid_on' => '2026-10-17', 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 90000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/loans/$id/payments/$payment/corrections", $this->input($id, $payment, 20000))->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('installments.0.status', 'paid')->assertJsonPath('data.recorded_paid_centimes', 100000);
    }

    public function test_invalid_identical_or_unconfirmed_corrections_are_rejected(): void
    {
        [$id,$payment] = $this->fixture();
        $input = $this->input($id, $payment);
        $url = "/api/v1/loans/$id/payments/$payment/corrections";
        foreach ([0, -1, 100.1, 100000, 250001] as $bad) {
            $this->postJson($url, [...$input, 'amount_centimes' => $bad])->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
        }
        foreach (['', '   ', str_repeat('a', 501)] as $bad) {
            $this->postJson($url, [...$input, 'reason' => $bad])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->postJson($url, [...$input, 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson($url, [...$input, 'company_id' => 100])->assertUnprocessable();
        $this->postJson($url, [...$input, 'correction_revision' => 9])->assertConflict();
        $this->postJson($url, [...$input, 'expected_remaining_centimes' => 0])->assertConflict();
        $this->postJson($url, [...$input, 'expected_loan_remaining_centimes' => 0])->assertConflict();
        $this->assertDatabaseCount('vehicle_loan_payment_corrections', 0);
    }

    public function test_new_payment_on_another_installment_invalidates_the_credit_preview(): void
    {
        [$id,$payment] = $this->fixture();
        $input = $this->input($id, $payment);
        $row = $this->getJson("/api/v1/loans/$id")->json('installments.1.id');
        $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => 10000, 'paid_on' => '2026-10-17', 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 250000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/loans/$id/payments/$payment/corrections", $input)->assertConflict();
        $this->postJson("/api/v1/loans/$id/payments/$payment/corrections", $this->input($id, $payment))->assertCreated();
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.recorded_paid_centimes', 20000)->assertJsonPath('data.remaining_centimes', 480000);
    }

    public function test_tenant_scope_and_responsible_only_and_append_only_storage(): void
    {
        [$id,$payment,,$company,$member] = $this->fixture();
        $input = $this->input($id, $payment);
        $url = "/api/v1/loans/$id/payments/$payment/corrections";
        $other = Company::factory()->create();
        $otherMember = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherMember->user)->withSession(['company_id' => $other->id]);
        $this->postJson($url, $input)->assertNotFound();
        $this->getJson("/api/v1/loans/$id")->assertNotFound();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->withSession(['company_id' => $company->id]);
        $this->postJson($url, $input)->assertForbidden();
        $this->actingAs($member->user)->withSession(['company_id' => null]);
        $this->postJson($url, $input)->assertForbidden();
        $this->withSession(['company_id' => $company->id]);
        $this->postJson($url, $input)->assertCreated();
        foreach (['UPDATE vehicle_loan_payment_corrections SET amount_centimes=1', 'DELETE FROM vehicle_loan_payment_corrections', 'TRUNCATE vehicle_loan_payment_corrections'] as $sql) {
            $rejected = false;
            try {
                DB::transaction(fn () => DB::statement($sql));
            } catch (QueryException) {
                $rejected = true;
            }$this->assertTrue($rejected);
        }
        $record = (array) DB::table('vehicle_loan_payment_corrections')->first();
        unset($record['id']);
        $record['company_id'] = $other->id;
        $record['actor_membership_id'] = $otherMember->id;
        $record['request_key'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('vehicle_loan_payment_corrections')->insert($record));
    }

    public function test_audit_failure_rolls_back_the_correction_and_balance(): void
    {
        [$id,$payment] = $this->fixture();
        $input = $this->input($id, $payment);
        $this->partialMock(VehicleLoans::class, fn ($mock) => $mock->shouldReceive('audit')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        $this->postJson("/api/v1/loans/$id/payments/$payment/corrections", $input)->assertStatus(500);
        $this->assertDatabaseCount('vehicle_loan_payment_corrections', 0);
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.recorded_paid_centimes', 100000)->assertJsonPath('data.remaining_centimes', 400000);
    }
}
