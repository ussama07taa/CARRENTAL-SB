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

class MaintenanceCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $paid = 20000, string $mode = 'completed'): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $items = [['kind' => 'oil', 'title' => 'Vidange', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000], ['kind' => 'repair', 'title' => 'Réparation saisie par erreur', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 40000]];
        foreach ($items as &$item) {
            $item['status'] = $mode === 'completed' ? 'done' : 'pending';
            $item['completed_on'] = $mode === 'completed' ? '2026-10-01' : null;
            $item['next_on'] = $mode === 'completed' ? '2026-11-01' : null;
        }
        $id = $this->postJson("$url/files", ['mode' => $mode, 'performed_on' => '2026-10-01', 'items' => $items, 'paid_centimes' => $paid, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $detail = $this->getJson("$url/$id/file")->assertOk()->json('data');

        return [$url, $id, $detail['items'][1]['item_key'], $member];
    }

    private function input(string $url, int $id, ?string $key = null): array
    {
        $d = $this->getJson("$url/$id/file")->assertOk()->json('data');

        return ['scope' => $key === null ? 'file' : 'work', 'item_key' => $key, 'reason' => 'Enregistrement en double par erreur', 'revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => $d['paid_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_cancel_work_preserves_original_and_payment_and_removes_only_its_cost_and_reminder(): void
    {
        [$url, $id, $key, $member] = $this->fixture();
        $before = DB::table('maintenance_work_items')->orderBy('id')->get()->toJson();
        $input = $this->input($url, $id, $key);
        $this->getJson($url)->assertJsonCount(2, 'reminders');

        $cid = $this->postJson("$url/$id/cancellations", $input)->assertCreated()->json('id');

        $this->postJson("$url/$id/cancellations", $input)->assertCreated()->assertJsonPath('id', $cid);
        $d = $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 65000)->assertJsonPath('data.paid_centimes', 20000)->assertJsonPath('data.balance_centimes', 45000)->assertJsonPath('data.items.1.status', 'cancelled')->assertJsonPath('data.items.1.amount_centimes', 40000)->assertJsonPath('data.items.1.cancellation.reason', $input['reason'])->assertJsonPath('data.items.1.cancellation.actor', $member->user->name)->json('data');
        $this->getJson($url)->assertJsonPath('summary.cost_centimes', 65000)->assertJsonPath('summary.balance_centimes', 45000)->assertJsonCount(1, 'reminders')->assertJsonPath('reminders.0.title', 'Vidange');
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertJsonPath('balance_centimes', 45000);
        $this->assertSame($before, DB::table('maintenance_work_items')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('maintenance_cancellations', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'maintenance_work_cancelled']);
        $this->postJson("$url/$id/work", [...$d['items'][1], 'status' => 'done', 'reason' => 'Tentative de reprise', 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertConflict();
    }

    public function test_paid_amount_blocks_file_and_work_cancellation_that_would_leave_unsettled_money(): void
    {
        [$url, $id, $key] = $this->fixture(80000);
        $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertUnprocessable()->assertJsonPath('message', 'Annulation bloquée : les paiements conservés dépasseraient le coût restant. Vérifiez les paiements et réglez leur situation avant de continuer.');
        $this->postJson("$url/$id/cancellations", $this->input($url, $id))->assertUnprocessable();
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 105000)->assertJsonPath('data.paid_centimes', 80000)->assertJsonPath('data.balance_centimes', 25000);
        $this->assertDatabaseCount('maintenance_cancellations', 0);
    }

    public function test_cancel_file_after_erroneous_payment_cancellation_keeps_history_but_excludes_active_tracking(): void
    {
        [$url, $id] = $this->fixture();
        $detail = $this->getJson("$url/$id/file")->json();
        $p = $detail['payments'][0];
        $this->postJson("$url/$id/payments/{$p['id']}/cancellations", ['reason' => 'Aucun paiement réel', 'correction_revision' => 0, 'work_revision' => $detail['data']['revision'], 'expected_paid_centimes' => 20000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $input = $this->input($url, $id);

        $cid = $this->postJson("$url/$id/cancellations", $input)->assertCreated()->json('id');

        $this->postJson("$url/$id/cancellations", $input)->assertCreated()->assertJsonPath('id', $cid);
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 0)->assertJsonPath('data.paid_centimes', 0)->assertJsonPath('data.cancellation.before_centimes', 105000)->assertJsonCount(2, 'data.items')->assertJsonCount(1, 'payments')->assertJsonPath('payments.0.original_amount_centimes', 20000);
        $this->getJson($url)->assertJsonPath('summary.cost_centimes', 0)->assertJsonCount(0, 'reminders')->assertJsonCount(0, 'debts')->assertJsonCount(1, 'cancelled');
        $this->getJson('/api/v1/maintenance/overview?tab=all')->assertJsonCount(0, 'data')->assertJsonPath('counts.all', 0)->assertJsonPath('counts.cancelled', 1);
        $this->getJson('/api/v1/maintenance/overview?tab=cancelled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.entry_id', $id)->assertJsonPath('data.0.reason', $input['reason']);
        $this->postJson("$url/$id/payments", ['amount_centimes' => 100, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson("$url/$id/work", ['item_key' => null, 'kind' => 'repair', 'title' => 'Tentative', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 100, 'completed_on' => '2026-10-01', 'revision' => $detail['data']['revision'], 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->assertDatabaseCount('maintenance_work_items', 2);
    }

    public function test_open_initial_garage_blocks_file_without_creating_return(): void
    {
        [$url, $id, $key] = $this->fixture(0, 'garage');
        $this->postJson("$url/$id/cancellations", $this->input($url, $id))->assertUnprocessable();
        $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertCreated();
        $this->getJson($url)->assertJsonCount(1, 'summary.garage');
        $this->assertDatabaseCount('maintenance_returns', 0);
    }

    public function test_deferred_work_disappears_from_tracking_and_cannot_be_restarted_after_cancellation(): void
    {
        [$url, $id, $key] = $this->fixture(0);
        $d = $this->getJson("$url/$id/file")->json('data');
        $this->postJson("$url/$id/work", [...$d['items'][1], 'status' => 'deferred', 'next_on' => null, 'next_km' => null, 'reason' => 'Pièce manquante', 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson($url)->assertJsonCount(1, 'followups');
        $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertCreated();
        $d = $this->getJson("$url/$id/file")->json('data');
        $this->getJson($url)->assertJsonCount(0, 'followups');
        $this->postJson("$url/$id/work/$key/follow-up", ['action' => 'start', 'revision' => $d['revision'], 'tracking_revision' => 0, 'occurred_on' => '2026-10-03', 'note' => 'Reprise', 'request_key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_open_followup_visit_blocks_work_and_file_cancellation_until_actual_return(): void
    {
        [$url, $id, $key] = $this->fixture(0);
        $d = $this->getJson("$url/$id/file")->json('data');
        $this->postJson("$url/$id/work", [...$d['items'][1], 'status' => 'deferred', 'next_on' => null, 'reason' => 'Report', 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()])->assertCreated();
        foreach (['start', 'defer', 'return'] as $action) {
            $d = $this->getJson("$url/$id/file")->json('data');
            $this->postJson("$url/$id/work/$key/follow-up", ['action' => $action, 'revision' => $d['revision'], 'tracking_revision' => $d['followups'][$key]['tracking_revision'], 'occurred_on' => '2026-10-03', 'note' => 'Suivi fictif', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
            if ($action === 'start') {
                $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertUnprocessable();
                $this->postJson("$url/$id/cancellations", $this->input($url, $id))->assertUnprocessable();
            }
        }
        $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertCreated();
        $this->getJson($url)->assertJsonCount(0, 'summary.garage')->assertJsonCount(0, 'followups');
    }

    public function test_blank_reason_stale_preview_and_tenant_or_employee_access_are_rejected(): void
    {
        [$url, $id, $key, $member] = $this->fixture(0);
        $endpoint = "$url/$id/cancellations";
        $input = $this->input($url, $id, $key);
        $this->postJson($endpoint, [...$input, 'reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($endpoint, [...$input, 'item_key' => (string) Str::uuid()])->assertNotFound();
        $this->postJson("$url/$id/payments", ['amount_centimes' => 100, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($endpoint, $input)->assertConflict();
        $employee = Tenant::run($member->company_id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->postJson($endpoint, $input)->assertForbidden();
        $this->fixture();
        $this->postJson($endpoint, $input)->assertNotFound();
        $this->assertDatabaseCount('maintenance_cancellations', 0);
    }

    public function test_legacy_work_can_be_cancelled_without_losing_original_record(): void
    {
        [$url] = $this->fixture(0);
        $id = $this->postJson($url, ['kind' => 'oil', 'title' => 'Ancienne vidange', 'performed_on' => '2026-10-02', 'cost_centimes' => 50000, 'paid_centimes' => 0, 'in_garage' => false, 'next_on' => '2026-11-02', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $before = (array) DB::table('vehicle_maintenance')->find($id);
        $input = [...$this->input($url, $id), 'scope' => 'work'];
        $this->postJson("$url/$id/cancellations", $input)->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 0)->assertJsonPath('data.items.0.status', 'cancelled')->assertJsonPath('data.items.0.amount_centimes', 50000);
        $this->assertSame($before, (array) DB::table('vehicle_maintenance')->find($id));
        $this->getJson($url)->assertJsonCount(2, 'reminders');
    }

    public function test_cancellation_history_is_immutable(): void
    {
        [$url, $id, $key] = $this->fixture();
        $this->postJson("$url/$id/cancellations", $this->input($url, $id, $key))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_cancellations')->delete();
    }

    public function test_audit_failure_rolls_back_work_cancellation(): void
    {
        [$url, $id, $key] = $this->fixture();
        $input = $this->input($url, $id, $key);
        DB::unprepared("CREATE FUNCTION reject_test_work_cancel_audit() RETURNS trigger AS $$ BEGIN IF NEW.action = 'maintenance_work_cancelled' THEN RAISE EXCEPTION 'test'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_work_cancel_audit_failure BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION reject_test_work_cancel_audit();");
        $this->postJson("$url/$id/cancellations", $input)->assertStatus(500);
        $this->assertDatabaseCount('maintenance_cancellations', 0);
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 105000)->assertJsonPath('data.paid_centimes', 20000);
    }
}
