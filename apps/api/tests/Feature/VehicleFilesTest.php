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

class VehicleFilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function member(bool $responsible = true): CompanyMembership
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]));
    }

    private function login(CompanyMembership $m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function input(array $data = []): array
    {
        return array_replace(['plate' => ' 12345-a-6 ', 'make' => 'Dacia', 'model' => 'Logan', 'creation_key' => (string) Str::uuid()], $data);
    }

    private function vehicle(CompanyMembership $m, array $data = []): Vehicle
    {
        return Tenant::run($m->company_id, fn () => Vehicle::factory()->create($data));
    }

    public function test_authentication_and_tenant_are_required(): void
    {
        $this->getJson('/api/v1/vehicles')->assertUnauthorized();
        $this->postJson('/api/v1/vehicles', $this->input())->assertUnauthorized();
        $m = $this->member();
        $this->actingAs($m->user)->getJson('/api/v1/vehicles')->assertForbidden();
    }

    public function test_required_fields_validation_and_no_fake_zero_reading(): void
    {
        $m = $this->member();
        $this->login($m);
        $this->postJson('/api/v1/vehicles', [])->assertUnprocessable()->assertJsonValidationErrors(['plate', 'make', 'model']);
        $this->postJson('/api/v1/vehicles', $this->input(['company_id' => 9, 'equipment' => ['unknown'], 'vin' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'equipment.0', 'vin']);
        $id = $this->postJson('/api/v1/vehicles', $this->input())->assertCreated()->assertJsonPath('data.plate', '12345-A-6')->assertJsonPath('data.current_odometer', null)->assertJsonMissingPath('data.company_id')->json('data.id');
        $this->assertDatabaseHas('vehicle_events', ['vehicle_id' => $id, 'action' => 'created', 'actor_membership_id' => $m->id]);
        $this->assertDatabaseCount('vehicle_odometer_readings', 0);
    }

    public function test_plate_and_vin_duplicates_blocked_per_agency_and_retry_is_idempotent(): void
    {
        $m = $this->member();
        $this->login($m);
        $input = $this->input(['vin' => 'WVWZZZ1JZXW000001']);
        $id = $this->postJson('/api/v1/vehicles', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/vehicles', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/vehicles', [...$input, 'model' => 'Other'])->assertConflict()->assertJsonPath('code', 'REQUEST_ALREADY_USED');
        $this->postJson('/api/v1/vehicles', $this->input(['plate' => '12345 A 6']))->assertConflict()->assertJsonPath('code', 'PLATE_ALREADY_USED');
        $this->postJson('/api/v1/vehicles', $this->input(['plate' => 'OTHER', 'vin' => $input['vin']]))->assertConflict()->assertJsonPath('code', 'VIN_ALREADY_USED');
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->login($this->member());
        $this->postJson('/api/v1/vehicles', $input)->assertCreated();
    }

    public function test_search_filters_and_tenant_isolation(): void
    {
        $mine = $this->member();
        $other = $this->member();
        $v = $this->vehicle($mine, ['plate' => 'TEST-A', 'plate_key' => 'TESTA', 'fuel' => 'diesel', 'transmission' => 'manual']);
        $foreign = $this->vehicle($other, ['make' => 'Private']);
        $this->login($mine);
        foreach (['test a', 'dacia logan', $v->reference] as $q) {
            $this->getJson('/api/v1/vehicles?q='.urlencode($q))->assertOk()->assertJsonCount(1, 'data');
        }
        $this->getJson('/api/v1/vehicles?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/vehicles?fuel=petrol')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/vehicles?transmission=manual&make=Dacia')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('Private');
        $this->getJson('/api/v1/vehicles/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/v1/vehicles/'.$foreign->id, ['plate' => 'OTHER', 'make' => 'Dacia', 'model' => 'Logan', 'version' => 1])->assertNotFound();
        $this->postJson('/api/v1/vehicles/'.$foreign->id.'/odometer', $this->reading())->assertNotFound();
    }

    public function test_edit_preserves_reference_and_rejects_stale_overwrite_and_direct_odometer_changes(): void
    {
        $m = $this->member();
        $v = $this->vehicle($m);
        $this->login($m);
        $data = ['plate' => 'NEW-PLATE', 'make' => 'Dacia', 'model' => 'Sandero', 'equipment' => ['bluetooth', 'carplay'], 'version' => 1];
        $this->putJson('/api/v1/vehicles/'.$v->id, $data)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.reference', $v->reference);
        $this->putJson('/api/v1/vehicles/'.$v->id, $data)->assertConflict();
        $this->putJson('/api/v1/vehicles/'.$v->id, [...$data, 'version' => 2, 'current_odometer' => 42])->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    private function reading(array $data = []): array
    {
        return array_replace(['kilometers' => 1100, 'recorded_on' => now()->toDateString(), 'kind' => 'reading', 'version' => 1, 'request_key' => (string) Str::uuid()], $data);
    }

    public function test_initial_and_later_odometer_history_and_idempotent_retry(): void
    {
        $m = $this->member();
        $this->login($m);
        $id = $this->postJson('/api/v1/vehicles', $this->input(['initial_odometer' => 1000, 'initial_odometer_date' => now()->toDateString()]))->assertCreated()->json('data.id');
        $url = "/api/v1/vehicles/$id/odometer";
        $input = $this->reading();
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.current_odometer', 1100)->assertJsonPath('data.version', 2);
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, [...$input, 'kilometers' => 1500])->assertConflict();
        $this->postJson($url, $this->reading(['version' => 2, 'kilometers' => 900]))->assertUnprocessable()->assertJsonValidationErrors('kilometers');
        $this->postJson($url, $this->reading(['version' => 2, 'kind' => 'correction', 'kilometers' => 900]))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, $this->reading(['version' => 2, 'kind' => 'correction', 'kilometers' => 900, 'reason' => 'Erreur de saisie vérifiée']))->assertCreated();
        $this->getJson("/api/v1/vehicles/$id")->assertOk()->assertJsonCount(3, 'readings')->assertJsonPath('readings.0.kind', 'correction')->assertJsonPath('data.current_odometer', 900);
    }

    public function test_invalid_dates_employee_correction_and_missing_initial_date_are_blocked(): void
    {
        $m = $this->member(false);
        $v = $this->vehicle($m, ['current_odometer' => 1000, 'current_odometer_date' => now()->toDateString()]);
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/odometer";
        $this->postJson($url, $this->reading(['kind' => 'correction', 'reason' => 'No']))->assertForbidden();
        $this->postJson($url, $this->reading(['recorded_on' => now()->subDay()->toDateString()]))->assertUnprocessable();
        $this->postJson($url, $this->reading(['recorded_on' => now()->addDay()->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/v1/vehicles', $this->input(['initial_odometer' => 0]))->assertUnprocessable()->assertJsonValidationErrors('initial_odometer_date');
        $this->postJson($url, $this->reading())->assertCreated();
    }

    public function test_database_uniqueness_is_final_guard(): void
    {
        $m = $this->member();
        $this->vehicle($m, ['plate' => 'ABC', 'plate_key' => 'ABC']);
        $this->expectException(QueryException::class);
        $this->vehicle($m, ['plate' => 'A B C', 'plate_key' => 'ABC']);
    }

    public function test_history_is_append_only(): void
    {
        $this->login($this->member());
        $this->postJson('/api/v1/vehicles', $this->input(['initial_odometer' => 0, 'initial_odometer_date' => now()->toDateString()]))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('vehicle_odometer_readings')->update(['kilometers' => 9]);
    }

    public function test_audit_failure_rolls_back_vehicle_creation(): void
    {
        $this->login($this->member());
        DB::unprepared("CREATE FUNCTION fail_vehicle_event() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit failure'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER fail_event BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION fail_vehicle_event();");
        $this->postJson('/api/v1/vehicles', $this->input())->assertServerError();
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_cross_tenant_composite_foreign_key_is_enforced(): void
    {
        $mine = $this->member();
        $other = $this->member();
        $v = $this->vehicle($mine);
        $this->expectException(QueryException::class);
        DB::table('vehicle_events')->insert(['company_id' => $mine->company_id, 'vehicle_id' => $v->id, 'actor_membership_id' => $other->id, 'action' => 'created', 'after' => '{}']);
    }

    public function test_demo_seed_rejects_non_demo_database(): void
    {
        $this->artisan('vehicles:demo')->assertFailed();
        $this->assertDatabaseCount('vehicles', 0);
    }
}
