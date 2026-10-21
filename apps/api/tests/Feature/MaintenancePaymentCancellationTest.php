<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenancePaymentCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $id = $this->postJson("$url/files", ['mode' => 'completed', 'performed_on' => '2026-10-01', 'items' => [['kind' => 'oil', 'title' => 'Vidange exemple', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000]], 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->postJson("$url/$id/payments", ['amount_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $detail = $this->getJson("$url/$id/file")->assertOk()->json();

        return [$url, $id, $detail['payments'][1]['id'], $member];
    }

    private function input(string $url, int $id): array
    {
        $detail = $this->getJson("$url/$id/file")->assertOk()->json();

        return ['reason' => 'Paiement de 200 DH saisi deux fois par erreur', 'correction_revision' => $detail['payments'][1]['correction_revision'], 'work_revision' => $detail['data']['revision'], 'expected_paid_centimes' => $detail['data']['paid_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_duplicate_200_payment_is_excluded_once_from_all_totals_and_history_is_preserved(): void
    {
        [$url, $id, $payment, $member] = $this->fixture();
        $original = (array) DB::table('maintenance_payments')->find($payment);
        $endpoint = "$url/$id/payments/$payment/cancellations";
        $input = $this->input($url, $id);
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 40000)->assertJsonPath('data.balance_centimes', 25000);

        $cancel = $this->postJson($endpoint, $input)->assertCreated()->json('id');

        $this->postJson($endpoint, $input)->assertCreated()->assertJsonPath('id', $cancel);
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 65000)->assertJsonPath('data.cost_final', true)->assertJsonPath('data.paid_centimes', 20000)->assertJsonPath('data.balance_centimes', 45000)->assertJsonCount(2, 'payments')->assertJsonPath('payments.1.amount_centimes', 0)->assertJsonPath('payments.1.original_amount_centimes', 20000)->assertJsonPath('payments.1.cancellation.before_centimes', 20000)->assertJsonPath('payments.1.cancellation.reason', $input['reason'])->assertJsonPath('payments.1.cancellation.actor', $member->user->name);
        $this->getJson($url)->assertJsonPath('summary.balance_centimes', 45000)->assertJsonPath('debts.0.amount_centimes', 45000);
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertJsonPath('balance_centimes', 45000)->assertJsonPath('data.0.amount_centimes', 45000);
        $this->assertSame($original, (array) DB::table('maintenance_payments')->find($payment));
        $this->assertDatabaseCount('maintenance_payments', 2);
        $this->assertDatabaseCount('maintenance_payment_cancellations', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'maintenance_payment_cancelled', 'actor_membership_id' => $member->id]);
        $this->postJson($endpoint, [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($endpoint, [...$input, 'reason' => 'Different request'])->assertConflict();
        $this->postJson("$url/$id/payments/$payment/corrections", [...$this->input($url, $id), 'amount_centimes' => 10000])->assertConflict();
        $this->postJson("$url/$id/payments", ['amount_centimes' => 45000, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 65000)->assertJsonPath('data.balance_centimes', 0)->assertJsonCount(3, 'payments');
    }

    public function test_cancellation_after_correction_uses_corrected_amount_and_rejects_old_preview(): void
    {
        [$url, $id, $payment] = $this->fixture();
        $old = $this->input($url, $id);
        $this->postJson("$url/$id/payments/$payment/corrections", [...$old, 'amount_centimes' => 10000])->assertCreated();
        $endpoint = "$url/$id/payments/$payment/cancellations";
        $this->postJson($endpoint, $old)->assertConflict();
        $this->assertDatabaseCount('maintenance_payment_cancellations', 0);

        $this->postJson($endpoint, $this->input($url, $id))->assertCreated();

        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 20000)->assertJsonPath('data.balance_centimes', 45000)->assertJsonPath('payments.1.cancellation.before_centimes', 10000)->assertJsonCount(1, 'payments.1.corrections')->assertJsonPath('payments.1.corrections.0.before_centimes', 20000);
    }

    public function test_reason_is_required_and_new_payment_invalidates_preview(): void
    {
        [$url, $id, $payment] = $this->fixture();
        $input = $this->input($url, $id);
        $endpoint = "$url/$id/payments/$payment/cancellations";
        foreach (['', '   ', str_repeat('x', 501)] as $reason) {
            $this->postJson($endpoint, [...$input, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->postJson("$url/$id/payments", ['amount_centimes' => 10000, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();

        $this->postJson($endpoint, $input)->assertConflict();

        $this->assertDatabaseCount('maintenance_payment_cancellations', 0);
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 50000);
    }

    public function test_employee_and_other_tenant_cannot_cancel_and_wrong_file_is_rejected(): void
    {
        [$url, $id, $payment, $member] = $this->fixture();
        $input = $this->input($url, $id);
        $endpoint = "$url/$id/payments/$payment/cancellations";
        $otherFile = $this->postJson("$url/files", ['mode' => 'completed', 'performed_on' => '2026-10-02', 'items' => [['kind' => 'repair', 'title' => 'Autre visite', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000]], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->postJson("$url/$otherFile/payments/$payment/cancellations", $input)->assertNotFound();
        $employee = Tenant::run($member->company_id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->postJson($endpoint, $input)->assertForbidden();
        $this->fixture();
        $this->postJson($endpoint, $input)->assertNotFound();
        $this->assertDatabaseCount('maintenance_payment_cancellations', 0);
    }

    public function test_guest_cannot_cancel_payment(): void
    {
        $this->postJson('/api/v1/vehicles/1/maintenance/1/payments/1/cancellations', [])->assertUnauthorized();
    }

    public function test_cancellation_is_immutable(): void
    {
        [$url, $id, $payment] = $this->fixture();
        $this->postJson("$url/$id/payments/$payment/cancellations", $this->input($url, $id))->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('maintenance_payment_cancellations')->delete();
    }

    public function test_failed_audit_rolls_back_cancellation_and_keeps_original_total(): void
    {
        [$url, $id, $payment] = $this->fixture();
        $input = $this->input($url, $id);
        DB::unprepared("CREATE FUNCTION reject_test_cancel_audit() RETURNS trigger AS $$ BEGIN IF NEW.action = 'maintenance_payment_cancelled' THEN RAISE EXCEPTION 'test'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_cancel_audit_failure BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION reject_test_cancel_audit();");

        $this->postJson("$url/$id/payments/$payment/cancellations", $input)->assertStatus(500);

        $this->assertDatabaseCount('maintenance_payment_cancellations', 0);
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 40000)->assertJsonPath('data.balance_centimes', 25000);
    }
}
