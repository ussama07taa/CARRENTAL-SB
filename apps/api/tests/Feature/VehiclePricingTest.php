<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehiclePricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-18T10:00Z'));
        $company = Company::factory()->create();
        [$actor,$car,$second] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);

        return [$company, $actor, $car, $second];
    }

    private function config(int $car, int $daily = 30000): array
    {
        return ['vehicles' => [['vehicle_id' => $car, 'daily_centimes' => $daily, 'minimum_days' => 0]], 'seasons' => []];
    }

    private function season(int $car, string $start = '2027-07-01', string $end = '2027-07-31', int $price = 40000, bool $active = true): array
    {
        return ['key' => (string) Str::uuid(), 'name' => 'Juillet', 'kind' => 'summer', 'starts_on' => $start, 'ends_on' => $end, 'active' => $active, 'rates' => [['vehicle_id' => $car, 'daily_centimes' => $price, 'minimum_days' => 0]]];
    }

    private function save(array $c, ?int $version = null, ?string $key = null)
    {
        return $this->postJson('/api/v1/pricing', ['configuration' => $c, 'expected_version' => $version, 'request_key' => $key ?? (string) Str::uuid(), 'reason' => 'Préparation été 2027']);
    }

    private function simulate(int $id, string $start, string $end)
    {
        return $this->postJson('/api/v1/pricing/simulate', ['vehicle_id' => $id, 'starts_local' => $start, 'ends_local' => $end]);
    }

    public function test_normal_half_day_daily_boundaries_and_season_start_rule_are_exact(): void
    {
        [,, $car] = $this->fixture();
        $c = $this->config($car->id);
        $c['seasons'][] = $this->season($car->id);
        $this->save($c)->assertCreated();
        $this->simulate($car->id, '2027-06-30T10:00', '2027-07-02T10:00')->assertOk()->assertJsonPath('data.total_centimes', 70000)->assertJsonPath('data.lines.0.label', 'Tarif normal')->assertJsonPath('data.lines.1.label', 'Juillet')->assertJsonCount(2, 'data.lines');
        $this->simulate($car->id, '2027-07-01T10:00', '2027-07-01T22:00')->assertOk()->assertJsonPath('data.total_centimes', 20000);
        $this->simulate($car->id, '2027-07-01T10:00', '2027-07-01T22:01')->assertOk()->assertJsonPath('data.total_centimes', 40000);
        $this->simulate($car->id, '2027-07-31T10:00', '2027-08-01T10:00')->assertOk()->assertJsonPath('data.total_centimes', 40000);
        $this->simulate($car->id, '2027-08-01T00:00', '2027-08-01T12:00')->assertOk()->assertJsonPath('data.total_centimes', 15000);
    }

    public function test_overlap_is_blocked_only_for_same_vehicle_and_active_periods(): void
    {
        [,, $car,$other] = $this->fixture();
        $c = $this->config($car->id);
        $c['vehicles'][] = ['vehicle_id' => $other->id, 'daily_centimes' => 35000, 'minimum_days' => 0];
        $c['seasons'] = [$this->season($car->id), $this->season($car->id, '2027-07-31', '2027-08-15')];
        $this->save($c)->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_pricing_versions', 0);
        $c['seasons'][1]['active'] = false;
        $v = $this->save($c)->assertCreated()->json('id');
        $c['seasons'][1]['rates'][0]['vehicle_id'] = $other->id;
        $c['seasons'][1]['active'] = true;
        $this->save($c, $v)->assertCreated();
    }

    public function test_minimum_duration_is_configurable_and_season_can_relax_normal_minimum(): void
    {
        [,, $car] = $this->fixture();
        $c = $this->config($car->id);
        $c['vehicles'][0]['minimum_days'] = 3;
        $c['seasons'][] = $this->season($car->id);
        $v = $this->save($c)->json('id');
        $this->simulate($car->id, '2027-06-01T10:00', '2027-06-02T10:00')->assertUnprocessable();
        $this->simulate($car->id, '2027-07-01T10:00', '2027-07-01T22:00')->assertOk();
        $c['seasons'][0]['rates'][0]['minimum_days'] = 5;
        $this->save($c, $v)->assertCreated();
        $this->simulate($car->id, '2027-07-01T10:00', '2027-07-04T10:00')->assertUnprocessable();
    }

    public function test_calendar_reference_survives_draft_activation_without_bypassing_price_or_overlap_checks(): void
    {
        [,, $car] = $this->fixture();
        $c = $this->config($car->id, 45000);
        $holiday = $this->season($car->id, '2027-01-24', '2027-01-31', 60000, false);
        $holiday['name'] = 'Vacances de mi-année 2026–2027';
        $holiday['kind'] = 'school';
        $holiday['calendar_id'] = 'men-2026-2027-midyear';
        $holiday['rates'] = [];
        $c['seasons'][] = $holiday;
        $v = $this->save($c)->assertCreated()->json('id');
        $this->getJson('/api/v1/pricing')->assertOk()->assertJsonPath('configuration.seasons.0.calendar_id', $holiday['calendar_id'])->assertJsonPath('configuration.seasons.0.active', false)->assertJsonCount(0, 'configuration.seasons.0.rates');
        $this->simulate($car->id, '2027-01-24T10:00', '2027-01-27T10:00')->assertOk()->assertJsonPath('data.total_centimes', 135000);
        $c['seasons'][0]['active'] = true;
        $this->save($c, $v)->assertUnprocessable();
        $c['seasons'][0]['rates'] = [['vehicle_id' => $car->id, 'daily_centimes' => 60000, 'minimum_days' => 0]];
        $v = $this->save($c, $v)->assertCreated()->json('id');
        $this->simulate($car->id, '2027-01-24T10:00', '2027-01-27T10:00')->assertOk()->assertJsonPath('data.total_centimes', 180000);
        $c['seasons'][] = $this->season($car->id, '2027-01-31', '2027-02-02');
        $this->save($c, $v)->assertUnprocessable();
        $c['seasons'] = [$holiday];
        $c['seasons'][0]['calendar_id'] = 'https://example.com/untrusted';
        $this->save($c, $v)->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_pricing_versions', 2);
    }

    public function test_money_rounds_only_once_and_no_automatic_long_stay_discount(): void
    {
        [,, $car] = $this->fixture();
        $this->save($this->config($car->id, 30001))->assertCreated();
        $this->simulate($car->id, '2027-06-01T10:00', '2027-06-01T22:00')->assertOk()->assertJsonPath('data.total_centimes', 15001)->assertJsonPath('data.lines.0.twice_centimes', 30001);
        $this->simulate($car->id, '2027-06-01T10:00', '2027-06-16T10:00')->assertOk()->assertJsonPath('data.total_centimes', 450015);
    }

    public function test_responsible_only_edits_tenant_isolation_and_append_only_history(): void
    {
        [$company,$actor,$car] = $this->fixture();
        $c = $this->config($car->id);
        $key = (string) Str::uuid();
        $v = $this->save($c, null, $key)->assertCreated()->json('id');
        $this->save($c, null, $key)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('vehicle_pricing_versions', 1);
        $this->save($c)->assertConflict();
        $c['vehicles'][0]['daily_centimes'] = 35000;
        $this->save($c, $v)->assertCreated();
        $this->assertDatabaseCount('vehicle_pricing_versions', 2);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->save($c)->assertForbidden();
        $this->simulate($car->id, '2027-06-01T10:00', '2027-06-02T10:00')->assertOk();
        $this->fixture();
        $this->simulate($car->id, '2027-06-01T10:00', '2027-06-02T10:00')->assertNotFound();
        $this->save($c)->assertUnprocessable();
    }

    public function test_postgresql_exclusion_and_immutability_protect_the_saved_grid(): void
    {
        [$company,,$car] = $this->fixture();
        $c = $this->config($car->id);
        $c['seasons'][] = $this->season($car->id);
        $v = $this->save($c)->json('id');
        try {
            DB::transaction(fn () => DB::table('vehicle_pricing_rates')->insert(['company_id' => $company->id, 'version_id' => $v, 'vehicle_id' => $car->id, 'season_key' => (string) Str::uuid(), 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-02', 'daily_centimes' => 100, 'minimum_days' => 0, 'active' => true]));
            $this->fail('Overlap accepted');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
        $this->expectException(QueryException::class);
        DB::table('vehicle_pricing_versions')->update(['reason' => 'rewrite']);
    }

    public function test_bad_dates_duplicate_rates_missing_price_and_unknown_vehicle_do_not_save(): void
    {
        [,, $car] = $this->fixture();
        $c = $this->config($car->id);
        $c['seasons'][] = $this->season($car->id, '2027-08-10', '2027-08-01');
        $this->save($c)->assertUnprocessable();
        $c['seasons'][0] = $this->season($car->id);
        $c['seasons'][0]['rates'][] = $c['seasons'][0]['rates'][0];
        $this->save($c)->assertUnprocessable();
        $c = $this->config($car->id, 0);
        $this->save($c)->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_pricing_versions', 0);
    }

    public function test_clock_changes_use_local_rental_days_and_reject_ambiguous_times(): void
    {
        [,, $car] = $this->fixture();
        $this->save($this->config($car->id))->assertCreated();
        $this->simulate($car->id, '2027-02-06T10:00', '2027-02-07T10:00')->assertOk()->assertJsonPath('data.total_centimes', 30000);
        $this->simulate($car->id, '2027-03-13T10:00', '2027-03-14T10:00')->assertOk()->assertJsonPath('data.total_centimes', 30000);
    }

    public function test_simulation_can_review_past_dates_and_refuses_empty_active_periods(): void
    {
        [,, $car] = $this->fixture();
        $c = $this->config($car->id);
        $v = $this->save($c)->json('id');
        $this->simulate($car->id, '2026-07-01T10:00', '2026-07-02T10:00')->assertOk()->assertJsonPath('data.total_centimes', 30000);
        $c['seasons'][] = $this->season($car->id);
        $c['seasons'][0]['rates'] = [];
        $this->save($c, $v)->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_pricing_versions', 1);
    }
}
