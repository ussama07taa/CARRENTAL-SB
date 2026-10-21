<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $company = Company::factory()->create();
        [$member, $customer, $vehicle] = Tenant::run($company->id, fn (): array => [CompanyMembership::factory()->create(['is_primary_responsible' => true]), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);

        return [$company, $customer, $vehicle];
    }

    private function payload(Customer $customer, Vehicle $vehicle): array
    {
        return ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()];
    }

    private function garage(Vehicle $vehicle): int
    {
        return $this->postJson("/api/v1/vehicles/$vehicle->id/maintenance/files", ['mode' => 'garage', 'performed_on' => '2026-10-18', 'items' => [['kind' => 'repair', 'title' => 'Réparation test', 'status' => 'progress', 'cost_status' => 'unknown']], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    public function test_saved_request_is_non_blocking_replay_safe_and_reopens_with_its_history(): void
    {
        [$company, $customer, $vehicle] = $this->agency();
        $payload = $this->payload($customer, $vehicle);
        $id = $this->postJson('/api/v1/reservations', $payload)->assertCreated()->assertJsonPath('data.status', 'demande')->assertJsonPath('data.blocks_vehicle', false)->assertJsonPath('data.duration_minutes', 4320)->json('data.id');
        $this->postJson('/api/v1/reservations', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('reservation_events')->where('company_id', $company->id)->count());
        $this->assertSame('2026-10-22 10:00:00+00', DB::table('reservations')->where('id', $id)->value('starts_at'));
        $this->getJson('/api/v1/reservations/'.$id)->assertOk()->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.vehicle.id', $vehicle->id)->assertJsonPath('data.starts_local', '2026-10-22T10:00')->assertJsonPath('events.0.action', 'request_created');
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00')->assertOk()->assertJsonPath('available_count', 1);
        $this->postJson('/api/v1/reservations', [...$payload, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/reservations')->assertJsonPath('total', 2);
        $this->postJson('/api/v1/reservations', [...$payload, 'ends_local' => '2026-10-26T10:00'])->assertConflict()->assertJsonPath('code', 'REQUEST_ALREADY_USED');
        $this->garage($vehicle);
        $this->postJson('/api/v1/reservations', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->travelTo(now()->addMonth());
        $this->postJson('/api/v1/reservations', $payload)->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_garage_is_checked_again_at_save_and_no_partial_request_is_saved(): void
    {
        [, $customer, $vehicle] = $this->agency();
        $payload = $this->payload($customer, $vehicle);
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00')->assertOk()->assertJsonPath('available_count', 1);
        $this->garage($vehicle);
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00')->assertJsonPath('available_count', 0)->assertJsonPath('unavailable_count', 1)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00&show=unavailable')->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.id', $vehicle->id);
        $this->postJson('/api/v1/reservations', $payload)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE')->assertJsonValidationErrors('vehicle_id');
        $this->assertSame(0, DB::table('reservations')->count());
        $this->assertSame(0, DB::table('reservation_events')->count());
    }

    public function test_bad_dates_and_moroccan_clock_changes_have_field_errors(): void
    {
        [, $customer, $vehicle] = $this->agency();
        $base = $this->payload($customer, $vehicle);
        foreach ([['starts_local' => 'bad'], ['starts_local' => '2026-10-17T10:00'], ['starts_local' => '2026-02-30T10:00'], ['ends_local' => $base['starts_local']], ['ends_local' => '2026-10-21T10:00']] as $change) {
            $this->postJson('/api/v1/reservations', [...$base, ...$change])->assertUnprocessable()->assertJsonValidationErrors(array_key_first($change));
        }
        $this->travelTo(now()->setDate(2026, 1, 1));
        foreach (['2026-02-15T02:30', '2026-03-22T02:30'] as $local) {
            $this->getJson('/api/v1/reservations/availability?starts_local='.$local.'&ends_local=2026-04-01T10:00')->assertUnprocessable()->assertJsonValidationErrors('starts_local');
        }
    }

    public function test_past_day_blocks_new_creation_but_preserves_existing_request_and_retry(): void
    {
        [, $customer, $vehicle] = $this->agency();
        $input = $this->payload($customer, $vehicle);
        $id = $this->postJson('/api/v1/reservations', $input)->assertCreated()->json('data.id');
        $before = DB::table('reservations')->where('id', $id)->first();
        $events = DB::table('reservation_events')->get()->toArray();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-23T00:01', 'Africa/Casablanca'));
        $this->postJson('/api/v1/reservations', [...$input, 'request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('errors.starts_local.0', 'La date de départ est passée. Choisissez une nouvelle date, aujourd’hui ou plus tard (heure du Maroc).');
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00')->assertUnprocessable()->assertJsonValidationErrors('starts_local');
        $this->postJson('/api/v1/reservations', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertEquals($before, DB::table('reservations')->where('id', $id)->first());
        $this->assertEquals($events, DB::table('reservation_events')->get()->toArray());
        $this->assertDatabaseCount('reservations', 1);
        $this->postJson('/api/v1/reservations', [...$input, 'starts_local' => '2026-10-23T10:00', 'request_key' => (string) Str::uuid()])->assertCreated();
    }

    public function test_customer_picker_marks_only_current_renters_without_blocking_new_requests(): void
    {
        [$company, $customer, $vehicle] = $this->agency();
        $input = $this->payload($customer, $vehicle);
        $id = $this->postJson('/api/v1/reservations', $input)->assertCreated()->json('data.id');
        $url = '/api/v1/customers?rental_status=1';
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.in_rental', false);
        DB::table('reservations')->where('id', $id)->update(['status' => 'en_location', 'confirmed_at' => now(), 'frozen_total_centimes' => 100000, 'preparation_minutes' => 0, 'blocked_until' => '2026-10-25T10:00:00+00:00']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.in_rental', true);
        $this->getJson('/api/v1/customers')->assertJsonMissingPath('data.0.in_rental');
        $otherCar = Tenant::run($company->id, fn () => Vehicle::factory()->create());
        $this->postJson('/api/v1/reservations', $this->payload($customer, $otherCar))->assertCreated();
        DB::table('reservations')->where('id', $id)->update(['status' => 'retournee']);
        $this->getJson($url)->assertJsonPath('data.0.in_rental', false);
        DB::table('reservations')->where('id', $id)->update(['status' => 'en_location']);
        [, $otherCustomer] = $this->agency();
        $this->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otherCustomer->id)->assertJsonPath('data.0.in_rental', false);
    }

    public function test_cross_tenant_data_and_injected_state_are_rejected(): void
    {
        [, $foreignCustomer, $foreignVehicle] = $this->agency();
        $foreignId = $this->postJson('/api/v1/reservations', $this->payload($foreignCustomer, $foreignVehicle))->json('data.id');
        [, $customer, $vehicle] = $this->agency();
        $base = $this->payload($customer, $vehicle);
        $this->postJson('/api/v1/reservations', [...$base, 'customer_id' => $foreignCustomer->id])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/reservations', [...$base, 'vehicle_id' => $foreignVehicle->id])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->postJson('/api/v1/reservations', [...$base, 'status' => 'confirmee', 'company_id' => 1])->assertUnprocessable()->assertJsonValidationErrors(['status', 'company_id']);
        $this->getJson('/api/v1/reservations/'.$foreignId)->assertNotFound();
        $this->getJson('/api/v1/reservations')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00')->assertJsonPath('available_count', 1)->assertJsonPath('data.0.id', $vehicle->id);
        $this->withSession(['company_id' => null])->getJson('/api/v1/reservations')->assertForbidden();
    }

    public function test_event_failure_rolls_back_creation_and_event_history_cannot_be_deleted(): void
    {
        [$company, $customer, $vehicle] = $this->agency();
        DB::unprepared("CREATE FUNCTION test_reject_request_event() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'test audit failure'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER reject_test_event BEFORE INSERT ON reservation_events FOR EACH STATEMENT EXECUTE FUNCTION test_reject_request_event();");
        $this->postJson('/api/v1/reservations', $this->payload($customer, $vehicle))->assertServerError();
        $this->assertSame(0, DB::table('reservations')->where('company_id', $company->id)->count());
        DB::unprepared('DROP TRIGGER reject_test_event ON reservation_events; DROP FUNCTION test_reject_request_event();');
        $this->postJson('/api/v1/reservations', $this->payload($customer, $vehicle))->assertCreated();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('reservation_events')->where('company_id', $company->id)->delete());
    }

    public function test_anonymous_access_is_denied(): void
    {
        $this->getJson('/api/v1/reservations')->assertUnauthorized();
        $this->getJson('/api/v1/reservations/availability')->assertUnauthorized();
        $this->postJson('/api/v1/reservations', [])->assertUnauthorized();
    }
}
