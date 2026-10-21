<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceOverviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function loginAgency(bool $responsible = true): array
    {
        $c = Company::factory()->create();
        [$m,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]), Vehicle::factory()->create(['plate' => 'TEST-SUIVI-01', 'make' => 'Dacia', 'model' => 'Logan'])]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);

        return [$c, $m, $v];
    }

    private function createEntry(Vehicle $v, string $mode = 'completed'): int
    {
        return $this->postJson("/api/v1/vehicles/$v->id/maintenance/files", ['mode' => $mode, 'performed_on' => '2026-10-01', 'items' => [['kind' => 'oil', 'title' => 'Vidange', 'status' => $mode === 'completed' ? 'done' : 'progress', 'cost_status' => 'estimate', 'amount_centimes' => 65000, 'completed_on' => $mode === 'completed' ? '2026-10-01' : null, 'next_on' => $mode === 'completed' ? '2026-10-20' : null]], 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'payment_due_on' => '2026-10-10', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    public function test_coverage_stays_fleet_wide_and_search_matches_dossier_and_work(): void
    {
        [$company,,$vehicle] = $this->loginAgency();
        $this->getJson('/api/v1/maintenance/overview?tab=files')->assertOk()
            ->assertJsonPath('counts.attention', 0)->assertJsonCount(1, 'coverage.without_history');
        $id = $this->createEntry($vehicle);
        Tenant::run($company->id, fn () => Vehicle::factory()->create());
        $this->getJson('/api/v1/maintenance/overview?tab=files&q=vidange')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.entry_id', $id)
            ->assertJsonPath('vehicle_count', 1)->assertJsonPath('coverage.vehicle_count', 2)
            ->assertJsonCount(1, 'coverage.without_history')->assertJsonCount(0, 'coverage.without_reminder');
        $this->getJson('/api/v1/maintenance/overview?tab=files&q='.urlencode('Dossier #'.$id))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('balance_centimes', 45000);
        $this->getJson('/api/v1/maintenance/overview?tab=all&q='.urlencode('Dossier #'.$id))
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('balance_centimes', 45000);
        $this->getJson('/api/v1/maintenance/overview?tab=files&q=absent')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('balance_centimes', 0)->assertJsonCount(1, 'coverage.without_history');
        $this->loginAgency();
        $this->getJson('/api/v1/maintenance/overview?tab=files&q=vidange')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('coverage.vehicle_count', 1)->assertJsonCount(1, 'coverage.without_history');
    }

    public function test_aggregate_matches_vehicle_totals_search_and_direct_actions(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17));
        [$c,$m,$v] = $this->loginAgency();
        $id = $this->createEntry($v);
        $single = $this->getJson("/api/v1/vehicles/$v->id/maintenance")->assertOk()->json();
        $r = $this->getJson('/api/v1/maintenance/overview?tab=all')->assertOk()->assertJsonPath('balance_centimes', $single['summary']['balance_centimes'])->assertJsonPath('provisional_files', 1)->assertJsonPath('counts.payments', 1)->assertJsonPath('counts.all', 2)->assertJsonPath('vehicle_count', 1)->json();
        $debt = collect($r['data'])->firstWhere('category', 'payments');
        $this->assertSame($id, $debt['entry_id']);
        $this->assertSame('pay', $debt['action']);
        $this->assertSame(45000, $debt['amount_centimes']);
        $this->getJson('/api/v1/maintenance/overview?q=logan&tab=all')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/maintenance/overview?q=unknown')->assertJsonPath('total', 0)->assertJsonPath('balance_centimes', 0);
        $this->getJson('/api/v1/maintenance/overview?tab=bad')->assertUnprocessable();
    }

    public function test_followup_moves_between_tabs_without_repeating_financial_balance(): void
    {
        [$c,$m,$v] = $this->loginAgency();
        $id = $this->createEntry($v, 'garage');
        $url = "/api/v1/vehicles/$v->id/maintenance/$id";
        $d = $this->getJson("$url/file")->json('data');
        $key = $d['items'][0]['item_key'];
        $this->postJson("$url/work", ['kind' => 'oil', 'title' => 'Vidange', 'status' => 'deferred', 'cost_status' => 'estimate', 'amount_centimes' => 65000, 'item_key' => $key, 'revision' => $d['revision'], 'reason' => 'Pièce attendue', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("$url/return", ['returned_on' => '2026-10-02', 'note' => 'Contrôlée', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/maintenance/overview?tab=deferred')->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'followup')->assertJsonPath('data.0.item_key', $key);
        $d = $this->getJson("$url/file")->json('data');
        $this->postJson("$url/work/$key/follow-up", ['action' => 'start', 'revision' => $d['revision'], 'tracking_revision' => 0, 'occurred_on' => '2026-10-03', 'note' => 'Reprise', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/maintenance/overview?tab=garage')->assertJsonPath('counts.deferred', 0)->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'followup')->assertJsonPath('balance_centimes', 45000)->assertJsonPath('counts.payments', 1);
    }

    public function test_files_include_paid_interventions_and_keep_one_row_per_dossier(): void
    {
        [$c, $m, $v] = $this->loginAgency();
        $url = "/api/v1/vehicles/$v->id/maintenance/files";
        $first = $this->postJson($url, ['mode' => 'completed', 'performed_on' => '2026-10-01', 'kilometers' => 15000, 'items' => [['kind' => 'oil', 'title' => 'Vidange soldée', 'status' => 'done', 'completed_on' => '2026-10-01', 'cost_status' => 'confirmed', 'amount_centimes' => 65000]], 'paid_centimes' => 65000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $second = $this->createEntry($v, 'garage');
        $response = $this->getJson('/api/v1/maintenance/overview?tab=files')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('counts.files', 2)->assertJsonPath('balance_centimes', 45000)->assertJsonCount(2, 'data');
        $files = collect($response->json('data'))->keyBy('entry_id');
        $this->assertSame('done', $files[$first]['work_state']);
        $this->assertFalse($files[$first]['garage_open']);
        $this->assertTrue($files[$first]['cost_final']);
        $this->assertSame(65000, $files[$first]['paid_centimes']);
        $this->assertSame(0, $files[$first]['balance_centimes']);
        $this->assertTrue($files[$second]['garage_open']);
        $this->getJson('/api/v1/maintenance/overview')->assertJsonCount(0, 'coverage.without_history')->assertJsonCount(1, 'coverage.without_reminder');
        $this->assertFalse($files[$second]['cost_final']);
        $this->getJson('/api/v1/maintenance/overview?tab=files&q=TEST-SUIVI')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/maintenance/overview?tab=files&q=absent')->assertJsonPath('total', 0);
        $this->loginAgency();
        $this->getJson('/api/v1/maintenance/overview?tab=files')->assertOk()->assertJsonPath('total', 0);
        $this->loginAgency(false);
        $this->getJson('/api/v1/maintenance/overview?tab=files')->assertForbidden();
    }

    public function test_files_paginate_all_interventions_of_one_vehicle(): void
    {
        [$c, $m, $v] = $this->loginAgency();
        for ($i = 0; $i < 21; $i++) {
            $this->createEntry($v);
        }
        $first = $this->getJson('/api/v1/maintenance/overview?tab=files')->assertOk()->assertJsonPath('total', 21)->assertJsonPath('counts.files', 21)->assertJsonPath('last_page', 2)->assertJsonCount(20, 'data')->json();
        $second = $this->getJson('/api/v1/maintenance/overview?tab=files&page=2')->assertOk()->assertJsonCount(1, 'data')->json();
        $this->assertEmpty(array_intersect(array_column($first['data'], 'entry_id'), array_column($second['data'], 'entry_id')));
    }

    public function test_access_is_tenant_isolated_and_responsible_only(): void
    {
        $this->getJson('/api/v1/maintenance/overview')->assertUnauthorized();
        [$c,$m,$v] = $this->loginAgency();
        $this->createEntry($v);
        $this->loginAgency();
        $this->getJson('/api/v1/maintenance/overview?tab=all')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('balance_centimes', 0);
        $this->loginAgency(false);
        $this->getJson('/api/v1/maintenance/overview')->assertForbidden();
    }

    public function test_300_vehicle_fleet_uses_batched_queries_and_stable_pagination(): void
    {
        [$c,$m,$v] = $this->loginAgency();
        $id = $this->createEntry($v);
        $root = DB::table('vehicle_maintenance')->find($id);
        $work = DB::table('maintenance_work_items')->where('maintenance_id', $id)->first();
        Tenant::run($c->id, function () use ($root, $work) {
            foreach (Vehicle::factory()->count(299)->create() as $car) {
                $e = (array) $root;
                unset($e['id']);
                $e['vehicle_id'] = $car->id;
                $e['request_key'] = (string) Str::uuid();
                $e['request_hash'] = hash('sha256', $e['request_key']);
                $newId = DB::table('vehicle_maintenance')->insertGetId($e);
                $w = (array) $work;
                unset($w['id']);
                $w['maintenance_id'] = $newId;
                $w['item_key'] = (string) Str::uuid();
                $w['request_key'] = (string) Str::uuid();
                $w['request_hash'] = hash('sha256', $w['request_key']);
                DB::table('maintenance_work_items')->insert($w);
            }
        });
        DB::enableQueryLog();
        $a = $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertOk()->assertJsonPath('vehicle_count', 300)->assertJsonPath('total', 300)->assertJsonCount(20, 'data')->json();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(20, count($queries), 'Fleet query count must not grow per vehicle or work item.');
        $b = $this->getJson('/api/v1/maintenance/overview?tab=payments&page=2')->assertOk()->assertJsonCount(20, 'data')->json();
        $this->assertEmpty(array_intersect(array_column($a['data'], 'key'), array_column($b['data'], 'key')));
        $this->assertSame(45000 + 299 * 65000, $a['balance_centimes']);
    }
}
