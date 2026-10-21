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

class VehicleLoanPaymentCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $opening = 0): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $loan = $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Banque fictive', 'reference' => 'DOUBLON', 'tracking_from' => '2026-10-01', 'schedule_confirmed' => true, 'opening_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [['due_on' => '2026-10-05', 'amount_centimes' => 250000, 'opening_paid_centimes' => $opening]]])->assertCreated()->json('id');
        $row = $this->getJson("/api/v1/loans/$loan")->json('installments.0.id');
        $payments = [];
        foreach ([250000 - $opening, 200000 - $opening] as $remaining) {
            $payments[] = $this->postJson("/api/v1/loans/$loan/installments/$row/payments", ['amount_centimes' => 50000, 'paid_on' => '2026-10-18', 'method' => 'transfer', 'receipt_confirmed' => true, 'expected_remaining_centimes' => $remaining, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        }

        return [$loan, $payments[1], $row, $company, $member, $payments[0]];
    }

    private function input(int $loan, int $payment): array
    {
        $data = $this->getJson("/api/v1/loans/$loan")->assertOk()->json();
        $p = collect($data['payments'])->firstWhere('id', $payment);
        $i = collect($data['installments'])->firstWhere('id', $p['installment_id']);

        return ['reason' => 'Même versement de 500 DH saisi deux fois', 'confirmed' => true, 'correction_revision' => $p['correction_revision'], 'financial_revision' => $data['financial_revision'], 'expected_remaining_centimes' => $i['remaining_centimes'], 'expected_loan_remaining_centimes' => $data['data']['remaining_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_duplicate_500_on_2500_keeps_original_and_recalculates_all_balances_once(): void
    {
        [$loan, $payment, , , $member, $first] = $this->fixture();
        $original = DB::table('vehicle_loan_payments')->orderBy('id')->get()->toJson();
        $url = "/api/v1/loans/$loan/payments/$payment/cancellations";
        $input = $this->input($loan, $payment);
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson($url, [...$input, 'reason' => 'Autre motif'])->assertConflict();
        $this->postJson($url, [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.recorded_paid_centimes', 50000)->assertJsonPath('data.remaining_centimes', 200000)->assertJsonPath('installments.0.remaining_centimes', 200000)->assertJsonPath('payments.0.amount_centimes', 50000)->assertJsonPath('payments.0.effective_amount_centimes', 0)->assertJsonPath('payments.0.cancellation_id', $id)->assertJsonPath('payments.1.id', $first)->assertJsonPath('payments.1.effective_amount_centimes', 50000)->assertJsonPath('cancellations.0.before_centimes', 50000)->assertJsonPath('cancellations.0.reason', $input['reason'])->assertJsonPath('cancellations.0.actor', $member->user->name)->assertJsonCount(2, 'payments');
        $this->getJson('/api/v1/loans?filter=overdue')->assertJsonPath('summary.month_centimes', 200000)->assertJsonPath('summary.overdue_centimes', 200000)->assertJsonPath('summary.remaining_centimes', 200000)->assertJsonPath('summary.recorded_paid_centimes', 50000);
        $this->assertSame($original, DB::table('vehicle_loan_payments')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('vehicle_loan_payment_cancellations', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'loan_payment_cancelled', 'actor_membership_id' => $member->id]);
        $this->postJson("/api/v1/loans/$loan/payments/$payment/corrections", [...$this->input($loan, $payment), 'amount_centimes' => 10000])->assertConflict();
    }

    public function test_cancellation_uses_last_corrected_amount_and_retains_correction_history(): void
    {
        [$loan, $payment] = $this->fixture();
        $stale = $this->input($loan, $payment);
        $this->postJson("/api/v1/loans/$loan/payments/$payment/corrections", [...$stale, 'amount_centimes' => 10025])->assertCreated();
        $url = "/api/v1/loans/$loan/payments/$payment/cancellations";
        $this->postJson($url, $stale)->assertConflict();
        $this->postJson($url, $this->input($loan, $payment))->assertCreated();
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.recorded_paid_centimes', 50000)->assertJsonPath('data.remaining_centimes', 200000)->assertJsonPath('cancellations.0.before_centimes', 10025)->assertJsonPath('corrections.0.amount_centimes', 10025)->assertJsonPath('payments.0.amount_centimes', 50000)->assertJsonPath('payments.0.effective_amount_centimes', 0);
    }

    public function test_settled_installment_reopens_without_changing_historical_payments_and_can_be_paid_again(): void
    {
        [$loan, $payment, $row] = $this->fixture(150000);
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('installments.0.status', 'paid');
        $this->postJson("/api/v1/loans/$loan/payments/$payment/cancellations", $this->input($loan, $payment))->assertCreated();
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.opening_paid_centimes', 150000)->assertJsonPath('data.next_due_on', '2026-10-05')->assertJsonPath('data.remaining_count', 1)->assertJsonPath('installments.0.status', 'overdue')->assertJsonPath('installments.0.remaining_centimes', 50000);
        $this->getJson('/api/v1/loans?filter=settled')->assertJsonPath('total', 0);
        $input = ['amount_centimes' => 50000, 'paid_on' => '2026-10-18', 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 50000, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/loans/$loan/installments/$row/payments", [...$input, 'amount_centimes' => 50001])->assertUnprocessable();
        $this->postJson("/api/v1/loans/$loan/installments/$row/payments", $input)->assertCreated();
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.remaining_centimes', 0)->assertJsonPath('data.recorded_paid_centimes', 100000)->assertJsonPath('data.opening_paid_centimes', 150000)->assertJsonCount(3, 'payments');
    }

    public function test_reason_confirmation_and_current_preview_are_required(): void
    {
        [$loan, $payment, $row] = $this->fixture();
        $input = $this->input($loan, $payment);
        $url = "/api/v1/loans/$loan/payments/$payment/cancellations";
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'confirmed', 'financial_revision', 'request_key']);
        foreach (['', '  ', str_repeat('a', 501)] as $reason) {
            $this->postJson($url, [...$input, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->postJson($url, [...$input, 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson($url, [...$input, 'company_id' => 1, 'amount_centimes' => 0])->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'amount_centimes']);
        foreach (['correction_revision', 'expected_remaining_centimes', 'expected_loan_remaining_centimes'] as $field) {
            $this->postJson($url, [...$input, $field => 99])->assertConflict();
        }
        $this->postJson("/api/v1/loans/$loan/installments/$row/payments", ['amount_centimes' => 10000, 'paid_on' => '2026-10-18', 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 150000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($url, $input)->assertConflict();
        $this->assertDatabaseCount('vehicle_loan_payment_cancellations', 0);
    }

    public function test_authentication_scope_and_immutable_tenant_owned_history(): void
    {
        [$loan, $payment, , $company, $member] = $this->fixture();
        $input = $this->input($loan, $payment);
        $url = "/api/v1/loans/$loan/payments/$payment/cancellations";
        auth()->forgetGuards();
        $this->postJson($url, $input)->assertUnauthorized();
        $other = Company::factory()->create();
        $otherMember = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherMember->user)->withSession(['company_id' => $other->id]);
        $this->postJson($url, $input)->assertNotFound();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->withSession(['company_id' => $company->id]);
        $this->postJson($url, $input)->assertForbidden();
        $this->actingAs($member->user)->withSession(['company_id' => null]);
        $this->postJson($url, $input)->assertForbidden();
        $this->withSession(['company_id' => $company->id]);
        $this->postJson("/api/v1/loans/999999/payments/$payment/cancellations", $input)->assertNotFound();
        $this->postJson($url, $input)->assertCreated();
        foreach (['UPDATE vehicle_loan_payment_cancellations SET reason=\'changed\'', 'DELETE FROM vehicle_loan_payment_cancellations', 'TRUNCATE vehicle_loan_payment_cancellations'] as $sql) {
            $rejected = false;
            try {
                DB::transaction(fn () => DB::statement($sql));
            } catch (QueryException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
        $record = (array) DB::table('vehicle_loan_payment_cancellations')->first();
        unset($record['id']);
        $record['company_id'] = $other->id;
        $record['actor_membership_id'] = $otherMember->id;
        $record['request_key'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('vehicle_loan_payment_cancellations')->insert($record));
    }

    public function test_audit_failure_keeps_payment_and_balance_unchanged(): void
    {
        [$loan, $payment] = $this->fixture();
        $input = $this->input($loan, $payment);
        $this->partialMock(VehicleLoans::class, fn ($mock) => $mock->shouldReceive('audit')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        $this->postJson("/api/v1/loans/$loan/payments/$payment/cancellations", $input)->assertStatus(500);
        $this->assertDatabaseCount('vehicle_loan_payment_cancellations', 0);
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.recorded_paid_centimes', 100000)->assertJsonPath('data.remaining_centimes', 150000);
    }
}
