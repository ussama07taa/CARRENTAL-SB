<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleNormalPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $responsible = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $company = Company::factory()->create();
        $actor = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]));
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);

        return [$company, $actor];
    }

    private function createPayload(?int $price = 30000): array
    {
        return ['plate' => 'TEST-'.Str::upper(Str::random(8)), 'make' => 'Dacia', 'model' => 'Logan', 'creation_key' => (string) Str::uuid(), ...($price === null ? [] : ['initial_daily_centimes' => $price])];
    }

    private function updatePrice(int $id, int $price, ?int $version, ?string $key = null)
    {
        return $this->postJson("/api/v1/vehicles/$id/pricing", ['daily_centimes' => $price, 'minimum_days' => 0, 'expected_version' => $version, 'request_key' => $key ?? (string) Str::uuid(), 'reason' => 'Prix normal modifié depuis le véhicule']);
    }

    public function test_initial_price_is_shared_and_creation_replay_does_not_reset_later_price(): void
    {
        $this->fixture();
        $payload = $this->createPayload();
        $id = $this->postJson('/api/v1/vehicles', $payload)->assertCreated()->json('data.id');
        $v = $this->getJson("/api/v1/vehicles/$id/pricing")->assertOk()->assertJsonPath('data.daily_centimes', 30000)->json('data.version_id');
        $this->getJson('/api/v1/pricing')->assertJsonPath('configuration.vehicles.0.daily_centimes', 30000);
        $this->updatePrice($id, 35000, $v)->assertCreated();
        $this->postJson('/api/v1/vehicles', $payload)->assertCreated();
        $this->getJson("/api/v1/vehicles/$id/pricing")->assertJsonPath('data.daily_centimes', 35000);
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('vehicle_pricing_versions', 2);
        $this->postJson('/api/v1/vehicles', [...$payload, 'initial_daily_centimes' => 40000])->assertConflict();
    }

    public function test_omitted_price_stays_unknown_and_employee_cannot_set_price(): void
    {
        $this->fixture(false);
        $this->postJson('/api/v1/vehicles', $this->createPayload())->assertForbidden();
        $this->assertDatabaseCount('vehicles', 0);
        $id = $this->postJson('/api/v1/vehicles', $this->createPayload(null))->assertCreated()->json('data.id');
        $this->getJson("/api/v1/vehicles/$id/pricing")->assertJsonPath('data.daily_centimes', null)->assertJsonPath('can_edit', false);
        $this->updatePrice($id, 30000, null)->assertForbidden();
        $this->assertDatabaseCount('vehicle_pricing_versions', 0);
    }

    public function test_edit_from_vehicle_preserves_other_cars_and_seasons_and_rejects_stale_grid(): void
    {
        $this->fixture();
        $one = $this->postJson('/api/v1/vehicles', $this->createPayload())->json('data.id');
        $two = $this->postJson('/api/v1/vehicles', $this->createPayload(40000))->json('data.id');
        $data = $this->getJson('/api/v1/pricing')->json();
        $data['configuration']['seasons'][] = ['key' => (string) Str::uuid(), 'name' => 'Juillet 2027', 'kind' => 'summer', 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-31', 'active' => true, 'rates' => [['vehicle_id' => $one, 'daily_centimes' => 50000, 'minimum_days' => 3]]];
        $version = $this->postJson('/api/v1/pricing', ['configuration' => $data['configuration'], 'expected_version' => $data['version_id'], 'request_key' => (string) Str::uuid(), 'reason' => 'Tarifs juillet'])->assertCreated()->json('id');
        $key = (string) Str::uuid();
        $this->updatePrice($one, 32000, $version, $key)->assertCreated();
        $this->updatePrice($one, 32000, $version, $key)->assertOk();
        $this->updatePrice($two, 42000, $version)->assertConflict();
        $after = $this->getJson('/api/v1/pricing')->json('configuration');
        $this->assertEquals($data['configuration']['seasons'], $after['seasons']);
        $this->assertSame(40000, collect($after['vehicles'])->firstWhere('vehicle_id', $two)['daily_centimes']);
        $this->updatePrice($two, 32000, $version, $key)->assertConflict();
    }

    public function test_pricing_error_rolls_back_new_vehicle_and_its_events(): void
    {
        $this->fixture();
        DB::unprepared("CREATE OR REPLACE FUNCTION fail_vehicle_price() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'test'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER fail_vehicle_price BEFORE INSERT ON vehicle_pricing_versions FOR EACH ROW EXECUTE FUNCTION fail_vehicle_price();");
        $this->postJson('/api/v1/vehicles', $this->createPayload())->assertServerError();
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('vehicle_pricing_versions', 0);
        DB::unprepared('DROP TRIGGER fail_vehicle_price ON vehicle_pricing_versions; DROP FUNCTION fail_vehicle_price();');
    }

    public function test_no_cross_tenant_price_access_and_invalid_prices_do_not_create(): void
    {
        $this->fixture();
        $this->postJson('/api/v1/vehicles', $this->createPayload(0))->assertUnprocessable();
        $this->postJson('/api/v1/vehicles', $this->createPayload(-10))->assertUnprocessable();
        $id = $this->postJson('/api/v1/vehicles', $this->createPayload())->json('data.id');
        $this->fixture();
        $this->getJson("/api/v1/vehicles/$id/pricing")->assertNotFound();
        $this->updatePrice($id, 30000, null)->assertNotFound();
    }

    public function test_vehicle_list_shows_latest_normal_price_and_odometer_date_without_guessing_missing_price(): void
    {
        [$company] = $this->fixture();
        $payload = [...$this->createPayload(45000), 'initial_odometer' => 15000, 'initial_odometer_date' => now('Africa/Casablanca')->toDateString()];
        $id = $this->postJson('/api/v1/vehicles', $payload)->assertCreated()->json('data.id');
        $missing = $this->postJson('/api/v1/vehicles', $this->createPayload(null))->assertCreated()->json('data.id');
        $grid = $this->getJson('/api/v1/pricing')->json();
        $grid['configuration']['seasons'][] = ['key' => (string) Str::uuid(), 'name' => 'Été', 'kind' => 'summer', 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-31', 'active' => true, 'rates' => [['vehicle_id' => $id, 'daily_centimes' => 90000, 'minimum_days' => 0]]];
        $version = $this->postJson('/api/v1/pricing', ['configuration' => $grid['configuration'], 'expected_version' => $grid['version_id'], 'request_key' => (string) Str::uuid(), 'reason' => 'Saison de test'])->assertCreated()->json('id');
        $this->updatePrice($id, 47000, $version)->assertCreated();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $before = DB::table('vehicle_pricing_versions')->count();
        $rows = collect($this->getJson('/api/v1/vehicles')->assertOk()->json('data'));
        $this->assertSame(47000, $rows->firstWhere('id', $id)['normal_daily_centimes']);
        $this->assertSame($payload['initial_odometer_date'], $rows->firstWhere('id', $id)['current_odometer_date']);
        $this->assertNull($rows->firstWhere('id', $missing)['normal_daily_centimes']);
        $this->assertSame($before, DB::table('vehicle_pricing_versions')->count());
    }
}
