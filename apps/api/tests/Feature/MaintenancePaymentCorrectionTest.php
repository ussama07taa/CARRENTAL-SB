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

class MaintenancePaymentCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $c = Company::factory()->create();
        [$m,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);
        $u = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson("$u/files", ['mode' => 'completed', 'performed_on' => '2026-10-01', 'items' => [['kind' => 'repair', 'title' => 'Réparation exemple', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 250000]], 'paid_centimes' => 200000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $d = $this->getJson("$u/$id/file")->json();

        return [$u, $id, $d['payments'][0]['id'], $m, $v];
    }

    private function input($u, $id, int $amount = 20000): array
    {
        $d = $this->getJson("$u/$id/file")->assertOk()->json();

        return ['amount_centimes' => $amount, 'reason' => 'Erreur de saisie : un zéro en trop', 'correction_revision' => $d['payments'][0]['correction_revision'], 'work_revision' => $d['data']['revision'], 'expected_paid_centimes' => $d['data']['paid_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_2000_corrected_to_200_updates_all_views_preserves_original_and_replays(): void
    {
        [$u,$id,$pid] = $this->fixture();
        $before = DB::table('maintenance_payments')->find($pid);
        $e = "$u/$id/payments/$pid/corrections";
        $input = $this->input($u, $id);
        $cid = $this->postJson($e, $input)->assertCreated()->json('id');
        $this->postJson($e, $input)->assertCreated()->assertJsonPath('id', $cid);
        $this->getJson("$u/$id/file")->assertJsonPath('data.paid_centimes', 20000)->assertJsonPath('data.balance_centimes', 230000)->assertJsonPath('payments.0.original_amount_centimes', 200000)->assertJsonPath('payments.0.amount_centimes', 20000)->assertJsonCount(1, 'payments.0.corrections')->assertJsonPath('payments.0.corrections.0.before_centimes', 200000);
        $this->getJson($u)->assertJsonPath('summary.balance_centimes', 230000)->assertJsonPath('debts.0.amount_centimes', 230000);
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertJsonPath('balance_centimes', 230000)->assertJsonPath('data.0.amount_centimes', 230000);
        $this->assertEquals($before, DB::table('maintenance_payments')->find($pid));
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->assertDatabaseCount('maintenance_payment_corrections', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'maintenance_payment_corrected']);
        $this->postJson("$u/$id/payments", ['amount_centimes' => 230000, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("$u/$id/file")->assertJsonPath('data.balance_centimes', 0)->assertJsonPath('data.paid_centimes', 250000);
    }

    public function test_multiple_corrections_use_latest_and_reject_stale_or_oversized_values(): void
    {
        [$u,$id,$pid] = $this->fixture();
        $e = "$u/$id/payments/$pid/corrections";
        $old = $this->input($u, $id);
        $this->postJson($e, $old)->assertCreated();
        $this->postJson($e, [...$old, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($e, $this->input($u, $id, 25000))->assertCreated();
        $this->getJson("$u/$id/file")->assertJsonPath('data.paid_centimes', 25000)->assertJsonCount(2, 'payments.0.corrections');
        $this->postJson($e, $this->input($u, $id, 260000))->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
        $this->postJson($e, $this->input($u, $id, 25000))->assertUnprocessable();
        $this->postJson($e, $this->input($u, $id, 0))->assertUnprocessable();
        $this->postJson($e, [...$this->input($u, $id), 'reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_new_payment_invalidates_old_preview_and_tenants_and_employees_are_blocked(): void
    {
        [$u,$id,$pid,$m,$v] = $this->fixture();
        $e = "$u/$id/payments/$pid/corrections";
        $input = $this->input($u, $id);
        $this->postJson("$u/$id/payments", ['amount_centimes' => 100, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($e, $input)->assertConflict();
        $employee = Tenant::run($m->company_id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user);
        $this->postJson($e, $input)->assertForbidden();
        $this->fixture();
        $this->postJson($e, $input)->assertNotFound();
    }

    public function test_correction_history_cannot_be_rewritten(): void
    {
        [$u,$id,$pid] = $this->fixture();
        $this->postJson("$u/$id/payments/$pid/corrections", $this->input($u, $id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_payment_corrections')->update(['reason' => 'rewrite']);
    }

    public function test_audit_failure_rolls_back_the_correction(): void
    {
        [$u,$id,$pid] = $this->fixture();
        $input = $this->input($u, $id);
        DB::unprepared("CREATE FUNCTION reject_test_correction_audit() RETURNS trigger AS $$ BEGIN IF NEW.action = 'maintenance_payment_corrected' THEN RAISE EXCEPTION 'test'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_audit_failure BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION reject_test_correction_audit();");
        $this->postJson("$u/$id/payments/$pid/corrections", $input)->assertStatus(500);
        $this->assertDatabaseCount('maintenance_payment_corrections', 0);
        $this->getJson("$u/$id/file")->assertJsonPath('data.paid_centimes', 200000);
    }
}
