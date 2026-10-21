<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceRefundCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $proof = false): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $id = $this->postJson("$url/files", ['mode' => 'completed', 'performed_on' => '2026-10-01', 'items' => [['kind' => 'oil', 'title' => 'Vidange fictive', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000]], 'paid_centimes' => 65000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $input = $this->input($url, $id);
        if ($proof) {
            $input['file'] = UploadedFile::fake()->createWithContent('preuve.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        }
        $refund = $this->postJson("$url/$id/refunds", [...$input, 'amount_centimes' => 50000, 'received_on' => '2026-10-02', 'method' => 'cash'])->assertCreated()->json('id');

        return [$url, $id, $refund, $member];
    }

    private function input(string $url, int $id): array
    {
        $d = $this->getJson("$url/$id/file")->assertOk()->json('data');

        return ['reason' => '500 DH saisis par erreur au lieu de 50 DH', 'work_revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => $d['paid_centimes'], 'expected_refunded_centimes' => $d['refunded_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_cancel_wrong_500_then_record_correct_50_preserves_original_proof_and_history(): void
    {
        Storage::fake('vehicle_documents');
        [$url, $id, $refund, $member] = $this->fixture(true);
        $original = DB::table('maintenance_refunds')->get()->toJson();
        $allocations = DB::table('maintenance_refund_allocations')->get()->toJson();
        $payments = DB::table('maintenance_payments')->get()->toJson();
        $input = $this->input($url, $id);
        $endpoint = "$url/$id/refunds/$refund/cancellations";
        $this->getJson("$url/$id/file")->assertJsonPath('data.refunded_centimes', 50000)->assertJsonPath('data.paid_centimes', 15000)->assertJsonPath('data.balance_centimes', 50000);
        $cid = $this->postJson($endpoint, $input)->assertCreated()->json('id');
        $this->postJson($endpoint, $input)->assertCreated()->assertJsonPath('id', $cid);
        $this->postJson($endpoint, [...$input, 'reason' => 'Requête différente'])->assertConflict();
        $this->postJson($endpoint, [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 65000)->assertJsonPath('data.paid_centimes', 65000)->assertJsonPath('data.refunded_centimes', 0)->assertJsonPath('data.balance_centimes', 0)->assertJsonPath('refunds.0.amount_centimes', 50000)->assertJsonPath('refunds.0.cancellation.reason', $input['reason'])->assertJsonPath('refunds.0.cancellation.actor', $member->user->name)->assertJsonPath('payments.0.available_centimes', 65000);
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertJsonPath('balance_centimes', 0)->assertJsonCount(0, 'data');
        $this->assertSame($original, DB::table('maintenance_refunds')->get()->toJson());
        $this->assertSame($allocations, DB::table('maintenance_refund_allocations')->get()->toJson());
        $this->assertSame($payments, DB::table('maintenance_payments')->get()->toJson());
        $this->assertDatabaseCount('maintenance_refund_cancellations', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'maintenance_refund_cancelled', 'actor_membership_id' => $member->id]);
        $this->get("$url/$id/refunds/$refund/content")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Storage::disk('vehicle_documents')->assertExists(DB::table('maintenance_refunds')->value('path'));

        $this->postJson("$url/$id/refunds", [...$this->input($url, $id), 'amount_centimes' => 5000, 'received_on' => '2026-10-02', 'method' => 'cash'])->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.gross_paid_centimes', 65000)->assertJsonPath('data.refunded_centimes', 5000)->assertJsonPath('data.paid_centimes', 60000)->assertJsonPath('data.balance_centimes', 5000)->assertJsonCount(2, 'refunds')->assertJsonPath('refunds.0.cancellation', null)->assertJsonPath('refunds.1.cancellation.id', $cid);
        $this->getJson($url)->assertJsonPath('data.0.refunded_centimes', 5000)->assertJsonPath('summary.balance_centimes', 5000);
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertJsonPath('balance_centimes', 5000)->assertJsonPath('data.0.amount_centimes', 5000);
    }

    public function test_reason_and_current_preview_are_required(): void
    {
        [$url, $id, $refund] = $this->fixture();
        $input = $this->input($url, $id);
        $endpoint = "$url/$id/refunds/$refund/cancellations";
        foreach (['', '   ', str_repeat('x', 501)] as $reason) {
            $this->postJson($endpoint, [...$input, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->postJson($endpoint, [...$input, 'amount_centimes' => 5000])->assertUnprocessable();
        $this->postJson($endpoint, [...$input, 'cancellation_revision' => 999])->assertConflict();
        $this->postJson($endpoint, [...$input, 'work_revision' => 999])->assertConflict();
        $this->postJson($endpoint, [...$input, 'expected_refunded_centimes' => 0])->assertConflict();
        $this->postJson("$url/$id/payments", ['amount_centimes' => 10000, 'paid_on' => '2026-10-03', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($endpoint, $input)->assertConflict();
        $this->assertDatabaseCount('maintenance_refund_cancellations', 0);
        $this->postJson($endpoint, $this->input($url, $id))->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 75000)->assertJsonPath('data.credit_centimes', 10000);
        $this->getJson('/api/v1/maintenance/overview?tab=credits')->assertJsonPath('data.0.amount_centimes', 10000);
    }

    public function test_cancelled_file_blocks_refund_cancellation_and_preserves_zero_net(): void
    {
        [$url, $id, $refund] = $this->fixture();
        $this->postJson("$url/$id/refunds", [...$this->input($url, $id), 'amount_centimes' => 15000, 'received_on' => '2026-10-02', 'method' => 'cash'])->assertCreated();
        $d = $this->getJson("$url/$id/file")->json('data');
        $this->postJson("$url/$id/cancellations", ['scope' => 'file', 'item_key' => null, 'reason' => 'Dossier erroné, avance rendue', 'revision' => $d['revision'], 'cancellation_revision' => 0, 'expected_paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("$url/$id/refunds/$refund/cancellations", $this->input($url, $id))->assertConflict();
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 0)->assertJsonPath('data.balance_centimes', 0)->assertJsonPath('data.refunded_centimes', 65000);
        $this->assertDatabaseCount('maintenance_refund_cancellations', 0);
    }

    public function test_only_active_refunds_protect_payments_and_reduce_available_funds(): void
    {
        [$url, $id, $refund] = $this->fixture();
        $this->postJson("$url/$id/refunds", [...$this->input($url, $id), 'amount_centimes' => 5000, 'received_on' => '2026-10-02', 'method' => 'cash'])->assertCreated();
        $this->postJson("$url/$id/refunds/$refund/cancellations", $this->input($url, $id))->assertCreated();
        $r = $this->getJson("$url/$id/file")->assertJsonPath('payments.0.refunded_centimes', 5000)->assertJsonPath('payments.0.available_centimes', 60000)->json();
        $payment = $r['payments'][0]['id'];
        $this->postJson("$url/$id/payments/$payment/cancellations", ['reason' => 'Erreur', 'correction_revision' => 0, 'work_revision' => $r['data']['revision'], 'expected_paid_centimes' => 60000, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("$url/$id/refunds", [...$this->input($url, $id), 'amount_centimes' => 60000, 'received_on' => '2026-10-02', 'method' => 'cash'])->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.refunded_centimes', 65000)->assertJsonPath('data.paid_centimes', 0);
        $this->postJson("$url/$id/refunds", [...$this->input($url, $id), 'amount_centimes' => 1, 'received_on' => '2026-10-02', 'method' => 'cash'])->assertUnprocessable();
    }

    public function test_guest_employee_other_tenant_and_wrong_file_are_rejected(): void
    {
        $this->postJson('/api/v1/vehicles/1/maintenance/1/refunds/1/cancellations', [])->assertUnauthorized();
        [$url, $id, $refund, $member] = $this->fixture();
        $input = $this->input($url, $id);
        $endpoint = "$url/$id/refunds/$refund/cancellations";
        $this->postJson("$url/999999/refunds/$refund/cancellations", $input)->assertNotFound();
        $this->postJson("$url/$id/refunds/999999/cancellations", $input)->assertNotFound();
        $employee = Tenant::run($member->company_id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->postJson($endpoint, $input)->assertForbidden();
        $this->fixture();
        $this->postJson($endpoint, $input)->assertNotFound();
        $this->assertDatabaseCount('maintenance_refund_cancellations', 0);
    }

    public function test_audit_failure_rolls_back_cancellation_and_calculation(): void
    {
        [$url, $id, $refund] = $this->fixture();
        $input = $this->input($url, $id);
        DB::unprepared("CREATE FUNCTION reject_test_refund_cancel_audit() RETURNS trigger AS $$ BEGIN IF NEW.action = 'maintenance_refund_cancelled' THEN RAISE EXCEPTION 'test'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_refund_cancel_failure BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION reject_test_refund_cancel_audit();");
        $this->postJson("$url/$id/refunds/$refund/cancellations", $input)->assertStatus(500);
        $this->assertDatabaseCount('maintenance_refund_cancellations', 0);
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 15000)->assertJsonPath('data.refunded_centimes', 50000);
    }

    public function test_cancellation_history_cannot_be_deleted(): void
    {
        [$url, $id, $refund] = $this->fixture();
        $this->postJson("$url/$id/refunds/$refund/cancellations", $this->input($url, $id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_refund_cancellations')->delete();
    }
}
