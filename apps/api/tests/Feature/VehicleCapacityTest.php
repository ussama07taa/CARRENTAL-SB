<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VehicleCapacityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function login(): void
    {
        $company = Company::factory()->create();
        $member = Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['plate' => 'TEST-VAN', 'make' => 'Renault', 'model' => 'Trafic', 'creation_key' => (string) Str::uuid()], $extra);
    }

    public function test_van_and_seat_capacity_are_saved_and_audited_without_inference(): void
    {
        $this->login();
        $id = $this->postJson('/api/v1/vehicles', $this->input(['vehicle_type' => 'van', 'seats' => 12]))->assertCreated()->assertJsonPath('data.vehicle_type', 'van')->assertJsonPath('data.seats', 12)->json('data.id');
        $this->putJson("/api/v1/vehicles/$id", ['plate' => 'TEST-VAN', 'make' => 'Renault', 'model' => 'Trafic', 'vehicle_type' => 'minibus', 'seats' => 17, 'version' => 1])->assertOk()->assertJsonPath('data.seats', 17)->assertJsonPath('data.vehicle_type', 'minibus');
        $this->getJson("/api/v1/vehicles/$id")->assertOk()->assertJsonPath('data.seats', 17);
        $event = DB::table('vehicle_events')->where('vehicle_id', $id)->latest('id')->first();
        $this->assertEquals(12, json_decode($event->before, true)['seats']);
        $this->assertEquals(17, json_decode($event->after, true)['seats']);
        $this->putJson("/api/v1/vehicles/$id", ['plate' => 'TEST-VAN', 'make' => 'Renault', 'model' => 'Trafic', 'version' => 2])->assertOk()->assertJsonPath('data.seats', null)->assertJsonPath('data.vehicle_type', null);
    }

    public function test_ordinary_vehicle_and_missing_capacity_remain_valid(): void
    {
        $this->login();
        $this->postJson('/api/v1/vehicles', $this->input(['vehicle_type' => 'van']))->assertCreated()->assertJsonPath('data.seats', null);
        $this->postJson('/api/v1/vehicles', $this->input(['plate' => 'TEST-CAR', 'seats' => 5]))->assertCreated()->assertJsonPath('data.vehicle_type', null)->assertJsonPath('data.seats', 5);
    }

    public static function invalidSeats(): array
    {
        return [[0], [-1], [100], [12.5], ['abc']];
    }

    #[DataProvider('invalidSeats')]
    public function test_invalid_capacity_is_rejected(mixed $seats): void
    {
        $this->login();
        $this->postJson('/api/v1/vehicles', $this->input(['seats' => $seats]))->assertUnprocessable()->assertJsonValidationErrors('seats');
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->login();
        $this->postJson('/api/v1/vehicles', $this->input(['vehicle_type' => 'invented']))->assertUnprocessable()->assertJsonValidationErrors('vehicle_type');
    }
}
