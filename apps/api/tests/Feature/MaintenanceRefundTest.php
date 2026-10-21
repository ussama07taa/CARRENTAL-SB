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

class MaintenanceRefundTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $paid = 100000): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $id = $this->postJson("$url/files", ['mode' => 'completed', 'performed_on' => '2026-10-01', 'items' => [['kind' => 'repair', 'title' => 'Réparation fictive', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 100000]], 'paid_centimes' => $paid, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');

        return [$url, $id, $member];
    }

    private function input(string $url, int $id, int $amount = 20000): array
    {
        $d = $this->getJson("$url/$id/file")->assertOk()->json('data');

        return ['amount_centimes' => $amount, 'received_on' => '2026-10-17', 'method' => 'cash', 'reason' => 'Trop-versé rendu par le garage', 'work_revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => $d['paid_centimes'], 'expected_refunded_centimes' => $d['refunded_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_partial_refunds_settle_excess_once_and_keep_original_payments_and_cost(): void
    {
        [$url, $id, $member] = $this->fixture();
        $d = $this->getJson("$url/$id/file")->json('data');
        $this->postJson("$url/$id/work", [...$d['items'][0], 'amount_centimes' => 65000, 'reason' => 'Prix final du garage confirmé à 650 DH', 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()])->assertCreated();
        $before = DB::table('maintenance_payments')->get()->toJson();
        $input = $this->input($url, $id);
        $rid = $this->postJson("$url/$id/refunds", $input)->assertCreated()->json('id');
        $this->postJson("$url/$id/refunds", $input)->assertCreated()->assertJsonPath('id', $rid);
        $this->postJson("$url/$id/refunds", [...$input, 'amount_centimes' => 20100])->assertConflict();
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 65000)->assertJsonPath('data.gross_paid_centimes', 100000)->assertJsonPath('data.refunded_centimes', 20000)->assertJsonPath('data.paid_centimes', 80000)->assertJsonPath('data.credit_centimes', 15000)->assertJsonPath('refunds.0.actor', $member->user->name)->assertJsonPath('payments.0.available_centimes', 80000);
        $this->getJson($url)->assertJsonPath('summary.credit_centimes', 15000)->assertJsonPath('data.0.refunded_centimes', 20000);
        $this->getJson('/api/v1/maintenance/overview?tab=credits')->assertJsonPath('data.0.amount_centimes', 15000);
        $this->postJson("$url/$id/refunds", $this->input($url, $id, 15000))->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 65000)->assertJsonPath('data.refunded_centimes', 35000)->assertJsonPath('data.balance_centimes', 0)->assertJsonPath('data.credit_centimes', 0)->assertJsonCount(2, 'refunds');
        $this->getJson('/api/v1/maintenance/overview?tab=credits')->assertJsonCount(0, 'data');
        $this->assertSame($before, DB::table('maintenance_payments')->get()->toJson());
        $this->assertDatabaseCount('maintenance_refunds', 2);
        $this->assertDatabaseCount('maintenance_refund_allocations', 2);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'maintenance_refund_received', 'actor_membership_id' => $member->id]);
    }

    public function test_full_real_refund_changes_net_not_cost_and_allows_cancelling_erroneous_file(): void
    {
        [$url, $id] = $this->fixture();
        $this->postJson("$url/$id/refunds", $this->input($url, $id, 100000))->assertCreated();
        $d = $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 100000)->assertJsonPath('data.paid_centimes', 0)->assertJsonPath('data.balance_centimes', 100000)->assertJsonCount(1, 'payments')->json('data');
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertJsonPath('balance_centimes', 100000);
        $this->postJson("$url/$id/cancellations", ['scope' => 'file', 'item_key' => null, 'reason' => 'Dossier fictif saisi sur la mauvaise voiture, avance rendue', 'revision' => $d['revision'], 'cancellation_revision' => 0, 'expected_paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 0)->assertJsonPath('data.balance_centimes', 0)->assertJsonPath('data.gross_paid_centimes', 100000)->assertJsonPath('data.refunded_centimes', 100000)->assertJsonCount(1, 'refunds')->assertJsonCount(1, 'payments');
        $this->postJson("$url/$id/refunds", $this->input($url, $id, 1))->assertConflict();
    }

    public function test_refunds_allocate_across_payments_and_protect_linked_payment_corrections_and_cancellation(): void
    {
        [$url, $id] = $this->fixture(30000);
        $this->postJson("$url/$id/payments", ['amount_centimes' => 70000, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("$url/$id/refunds", $this->input($url, $id, 50000))->assertCreated();
        $r = $this->getJson("$url/$id/file")->assertJsonPath('refunds.0.allocations.0.amount_centimes', 30000)->assertJsonPath('refunds.0.allocations.1.amount_centimes', 20000)->assertJsonPath('data.paid_centimes', 50000)->json();
        $p = $r['payments'][1];
        $input = ['reason' => 'Correction de saisie', 'correction_revision' => 0, 'work_revision' => $r['data']['revision'], 'expected_paid_centimes' => 50000, 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/payments/{$p['id']}/cancellations", $input)->assertUnprocessable();
        $this->postJson("$url/$id/payments/{$p['id']}/corrections", [...$input, 'amount_centimes' => 10000])->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
        $this->postJson("$url/$id/payments/{$p['id']}/corrections", [...$input, 'amount_centimes' => 40000])->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.gross_paid_centimes', 70000)->assertJsonPath('data.refunded_centimes', 50000)->assertJsonPath('data.paid_centimes', 20000)->assertJsonPath('data.balance_centimes', 80000);
        $this->postJson("$url/$id/refunds", $this->input($url, $id, 20001))->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
    }

    public function test_invalid_amount_date_reason_and_stale_preview_are_rejected(): void
    {
        [$url, $id] = $this->fixture();
        $input = $this->input($url, $id);
        foreach ([['amount_centimes' => 0], ['amount_centimes' => 100001], ['amount_centimes' => 1.5], ['received_on' => '2026-10-18'], ['received_on' => '2026-09-30'], ['reason' => ' '], ['method' => 'cheque']] as $invalid) {
            $this->postJson("$url/$id/refunds", [...$input, ...$invalid])->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid));
        }
        $this->postJson("$url/$id/refunds", $input)->assertCreated();
        $this->postJson("$url/$id/refunds", [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('maintenance_refunds', 1);
    }

    public function test_cancelled_payments_do_not_create_refundable_funds(): void
    {
        [$url, $id] = $this->fixture();
        $r = $this->getJson("$url/$id/file")->json();
        $this->postJson("$url/$id/payments/{$r['payments'][0]['id']}/cancellations", ['reason' => 'Aucun paiement réel', 'correction_revision' => 0, 'work_revision' => $r['data']['revision'], 'expected_paid_centimes' => 100000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("$url/$id/refunds", $this->input($url, $id, 1))->assertUnprocessable();
        $this->assertDatabaseCount('maintenance_refunds', 0);
    }

    public function test_private_proof_and_tenant_and_responsible_boundaries(): void
    {
        Storage::fake('vehicle_documents');
        [$url, $id, $member] = $this->fixture();
        $input = $this->input($url, $id);
        $rid = $this->postJson("$url/$id/refunds", [...$input, 'file' => UploadedFile::fake()->createWithContent('preuve.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF")])->assertCreated()->json('id');
        $path = DB::table('maintenance_refunds')->value('path');
        Storage::disk('vehicle_documents')->assertExists($path);
        $endpoint = "$url/$id/refunds/$rid/content";
        $this->get($endpoint)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson("$url/$id/file")->assertJsonPath('refunds.0.has_file', true)->assertDontSee($path);
        $employee = Tenant::run($member->company_id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->postJson("$url/$id/refunds", $input)->assertForbidden();
        $this->getJson($endpoint)->assertForbidden();
        $this->fixture();
        $this->postJson("$url/$id/refunds", $input)->assertNotFound();
        $this->getJson($endpoint)->assertNotFound();
    }

    public function test_guest_and_invalid_proof_are_rejected(): void
    {
        $this->postJson('/api/v1/vehicles/1/maintenance/1/refunds', [])->assertUnauthorized();
        [$url, $id] = $this->fixture();
        $this->postJson("$url/$id/refunds", [...$this->input($url, $id), 'file' => UploadedFile::fake()->createWithContent('bad.pdf', '<script>bad</script>')])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_failed_audit_rolls_back_refund_allocations_and_private_proof(): void
    {
        Storage::fake('vehicle_documents');
        [$url, $id] = $this->fixture();
        $input = $this->input($url, $id);
        DB::unprepared("CREATE FUNCTION reject_test_refund_audit() RETURNS trigger AS $$ BEGIN IF NEW.action = 'maintenance_refund_received' THEN RAISE EXCEPTION 'test'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_refund_audit_failure BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION reject_test_refund_audit();");
        $this->postJson("$url/$id/refunds", [...$input, 'file' => UploadedFile::fake()->createWithContent('preuve.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF")])->assertStatus(500);
        $this->assertDatabaseCount('maintenance_refunds', 0);
        $this->assertDatabaseCount('maintenance_refund_allocations', 0);
        $this->assertCount(0, Storage::disk('vehicle_documents')->allFiles());
        $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 100000)->assertJsonPath('data.refunded_centimes', 0);
    }

    public function test_refund_history_is_immutable(): void
    {
        [$url, $id] = $this->fixture();
        $this->postJson("$url/$id/refunds", $this->input($url, $id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_refunds')->delete();
    }

    public function test_refund_allocations_are_immutable(): void
    {
        [$url, $id] = $this->fixture();
        $this->postJson("$url/$id/refunds", $this->input($url, $id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_refund_allocations')->update(['amount_centimes' => 1]);
    }
}
