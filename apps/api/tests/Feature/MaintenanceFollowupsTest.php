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

class MaintenanceFollowupsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $c = Company::factory()->create();
        [$m,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $work = ['kind' => 'body', 'title' => 'Carrosserie reportée', 'status' => 'progress', 'cost_status' => 'estimate', 'amount_centimes' => 120000];
        $id = $this->postJson("$url/files", ['mode' => 'garage', 'performed_on' => '2026-10-01', 'items' => [$work], 'paid_centimes' => 50000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $d = $this->getJson("$url/$id/file")->json('data');
        $key = $d['items'][0]['item_key'];
        $this->postJson("$url/$id/work", [...$work, 'status' => 'deferred', 'item_key' => $key, 'revision' => $d['revision'], 'reason' => 'Pièce indisponible', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("$url/$id/return", ['returned_on' => '2026-10-02', 'note' => 'Contrôle terminé', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();

        return [$url, $id, $key];
    }

    private function input($url, $id, $key, $action, array $extra = []): array
    {
        $d = $this->getJson("$url/$id/file")->assertOk()->json('data');

        return array_replace(['action' => $action, 'revision' => $d['revision'], 'tracking_revision' => $d['followups'][$key]['tracking_revision'], 'occurred_on' => '2026-10-03', 'note' => 'Suivi fictif vérifié', 'request_key' => (string) Str::uuid()], $extra);
    }

    public function test_plan_and_direct_completion_preserve_cost_and_payment(): void
    {
        [$u,$id,$key] = $this->fixture();
        $endpoint = "$u/$id/work/$key/follow-up";
        $plan = $this->input($u, $id, $key, 'plan', ['follow_up_on' => '2026-10-20']);
        $event = $this->postJson($endpoint, $plan)->assertCreated()->json('id');
        $this->postJson($endpoint, $plan)->assertCreated()->assertJsonPath('id', $event);
        $this->getJson($u)->assertJsonCount(1, 'followups')->assertJsonPath('followups.0.follow_up_on', '2026-10-20');
        $this->postJson($endpoint, $this->input($u, $id, $key, 'complete', ['kilometers' => 47000, 'next_km' => 57000]))->assertCreated();
        $this->getJson($u)->assertJsonCount(0, 'followups');
        $this->getJson("$u/$id/file")->assertJsonPath('data.cost_centimes', 120000)->assertJsonPath('data.paid_centimes', 50000)->assertJsonPath('data.balance_centimes', 70000)->assertJsonPath('data.cost_final', false)->assertJsonPath('data.items.0.status', 'done');
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
    }

    public function test_reprise_completion_and_return_are_separate_and_never_duplicate_expense(): void
    {
        [$u,$id,$key] = $this->fixture();
        $e = "$u/$id/work/$key/follow-up";
        $start = $this->input($u, $id, $key, 'start');
        $this->postJson($e, $start)->assertCreated();
        $this->postJson($e, [...$start, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson($u)->assertJsonCount(1, 'summary.garage');
        $this->postJson($e, $this->input($u, $id, $key, 'return', ['ready' => true]))->assertUnprocessable()->assertJsonValidationErrors('ready');
        $this->postJson($e, $this->input($u, $id, $key, 'complete'))->assertCreated();
        $this->getJson($u)->assertJsonCount(1, 'summary.garage')->assertJsonCount(1, 'followups');
        $this->postJson($e, $this->input($u, $id, $key, 'return', ['ready' => true]))->assertCreated();
        $this->getJson($u)->assertJsonCount(0, 'summary.garage')->assertJsonCount(0, 'followups');
        $this->getJson("$u/$id/file")->assertJsonPath('data.cost_centimes', 120000)->assertJsonPath('data.paid_centimes', 50000)->assertJsonPath('data.balance_centimes', 70000);
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
    }

    public function test_repeated_deferral_requires_return_before_another_visit(): void
    {
        [$u,$id,$key] = $this->fixture();
        $e = "$u/$id/work/$key/follow-up";
        $this->postJson($e, $this->input($u, $id, $key, 'start'))->assertCreated();
        $this->postJson($e, $this->input($u, $id, $key, 'defer', ['follow_up_on' => '2026-10-25']))->assertCreated();
        $this->postJson($e, $this->input($u, $id, $key, 'start'))->assertUnprocessable();
        $this->postJson($e, $this->input($u, $id, $key, 'return', ['ready' => true]))->assertCreated();
        $this->getJson($u)->assertJsonCount(1, 'followups');
        $this->postJson($e, $this->input($u, $id, $key, 'start'))->assertCreated();
        $this->assertDatabaseCount('vehicle_maintenance', 1);
    }

    public function test_dates_prices_and_cross_tenant_access_are_guarded(): void
    {
        [$u,$id,$key] = $this->fixture();
        $e = "$u/$id/work/$key/follow-up";
        $this->postJson($e, $this->input($u, $id, $key, 'complete', ['occurred_on' => '2026-10-01']))->assertUnprocessable()->assertJsonValidationErrors('occurred_on');
        $this->postJson($e, $this->input($u, $id, $key, 'complete', ['amount_centimes' => 1]))->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
        $input = $this->input($u, $id, $key, 'start');
        $this->fixture();
        $this->postJson($e, $input)->assertNotFound();
    }

    public function test_tracking_history_is_immutable(): void
    {
        [$u,$id,$key] = $this->fixture();
        $this->postJson("$u/$id/work/$key/follow-up", $this->input($u, $id, $key, 'plan'))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_followups')->update(['note' => 'rewrite']);
    }
}
