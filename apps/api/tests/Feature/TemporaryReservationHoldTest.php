<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TemporaryReservationHoldTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $responsible = true): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18T10:00:00Z'));
        $company = Company::factory()->create();
        [$member, $customer, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);

        return [$company, $member, $customer, $vehicle];
    }

    private function requestFor(Customer $customer, Vehicle $vehicle, string $start = '2026-10-22T10:00', string $end = '2026-10-25T10:00'): int
    {
        return $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => $start, 'ends_local' => $end, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function hold(int $id, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/hold", ['request_key' => $key ?? (string) Str::uuid(), 'agreement_confirmed' => true]);
    }

    public function test_hold_blocks_competing_request_without_removing_it_and_updates_availability_and_planning(): void
    {
        [$company, $member, $client, $car] = $this->fixture();
        $one = $this->requestFor($client, $car);
        $two = $this->requestFor($client, $car);
        $this->hold($one)->assertCreated()->assertJsonPath('data.status', 'temporaire')->assertJsonPath('data.blocks_vehicle', true)->assertJsonPath('data.preparation_minutes', 120)->assertJsonPath('data.blocked_until_local', '2026-10-25T12:00');
        $this->assertTrue(now()->addHours(2)->equalTo(CarbonImmutable::parse(DB::table('reservations')->where('id', $one)->value('hold_expires_at'))));
        $this->getJson("/api/v1/reservations/$two")->assertOk()->assertJsonPath('data.status', 'demande')->assertJsonPath('data.conflicts.0.id', $one);
        $this->hold($two)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->getJson('/api/v1/reservations')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00&show=unavailable')->assertOk()->assertJsonPath('data.0.available', false);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-22&days=7')->assertOk()->assertJsonPath('data.0.events.0.kind', 'hold')->assertJsonPath('data.0.events.0.blocks_vehicle', true);
        $this->assertDatabaseCount('reservation_events', 3);
    }

    public function test_idempotency_never_extends_or_reactivates_an_expired_hold(): void
    {
        [, , $client, $car] = $this->fixture();
        $id = $this->requestFor($client, $car);
        $key = (string) Str::uuid();
        $expiry = $this->hold($id, $key)->assertCreated()->json('data.hold_expires_at');
        $this->travel(30)->minutes();
        $this->hold($id, $key)->assertOk()->assertJsonPath('data.hold_expires_at', $expiry);
        $this->hold($id)->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
        $this->travel(91)->minutes();
        $this->hold($id, $key)->assertOk()->assertJsonPath('data.status', 'demande')->assertJsonPath('data.blocks_vehicle', false);
        $this->assertDatabaseCount('reservation_hold_requests', 1);
        $this->assertDatabaseCount('reservation_events', 3);
        $this->hold($id)->assertCreated();
    }

    public function test_exact_expiry_releases_the_car_and_retains_request_and_history_once(): void
    {
        [, , $client, $car] = $this->fixture();
        $one = $this->requestFor($client, $car);
        $two = $this->requestFor($client, $car);
        $this->hold($one)->assertCreated();
        $this->travel(2)->hours();
        $this->hold($two)->assertCreated();
        $this->getJson("/api/v1/reservations/$one")->assertJsonPath('data.status', 'demande')->assertJsonPath('data.hold_expired', true)->assertJsonPath('data.conflicts.0.id', $two);
        $this->artisan('reservations:expire-holds')->assertSuccessful();
        $this->assertSame(1, DB::table('reservation_events')->where('reservation_id', $one)->where('action', 'hold_expired')->count());
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_stored_buffer_and_half_open_boundaries_are_respected(): void
    {
        [$company, , $client, $car] = $this->fixture();
        $one = $this->requestFor($client, $car);
        $adjacent = $this->requestFor($client, $car, '2026-10-25T12:00', '2026-10-26T10:00');
        $overlap = $this->requestFor($client, $car, '2026-10-25T11:59', '2026-10-26T10:00');
        $this->hold($one)->assertCreated();
        DB::table('company_settings')->where('company_id', $company->id)->update(['preparation_minutes' => 0]);
        $this->hold($overlap)->assertConflict();
        $this->hold($adjacent)->assertCreated()->assertJsonPath('data.preparation_minutes', 0);
        $this->getJson("/api/v1/reservations/$one")->assertJsonPath('data.preparation_minutes', 120)->assertJsonPath('data.blocked_until_local', '2026-10-25T12:00');
    }

    public function test_garage_entry_after_request_blocks_hold_and_confirmation_is_required(): void
    {
        [, , $client, $car] = $this->fixture();
        $id = $this->requestFor($client, $car);
        $this->postJson("/api/v1/reservations/$id/hold", ['request_key' => (string) Str::uuid(), 'agreement_confirmed' => false])->assertUnprocessable();
        $this->postJson("/api/v1/vehicles/$car->id/maintenance/files", ['mode' => 'garage', 'performed_on' => '2026-10-18', 'items' => [['kind' => 'repair', 'title' => 'Réparation', 'status' => 'pending', 'cost_status' => 'unknown']], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->hold($id)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->assertDatabaseCount('reservation_hold_requests', 0);
    }

    public function test_existing_members_can_hold_but_cross_tenant_and_injected_expiry_are_rejected(): void
    {
        [, , $client, $car] = $this->fixture(false);
        $id = $this->requestFor($client, $car);
        $this->postJson("/api/v1/reservations/$id/hold", ['request_key' => (string) Str::uuid(), 'agreement_confirmed' => true, 'preparation_minutes' => 0])->assertUnprocessable();
        $this->hold($id)->assertCreated();
        $this->fixture();
        $this->hold($id)->assertNotFound();
        $this->getJson('/api/v1/reservations')->assertJsonPath('total', 0);
    }

    public function test_postgresql_exclusion_is_the_final_overlap_guard(): void
    {
        [, , $client, $car] = $this->fixture();
        $one = $this->requestFor($client, $car);
        $two = $this->requestFor($client, $car);
        $this->hold($one)->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('reservations')->where('id', $two)->update(['status' => 'temporaire', 'hold_expires_at' => now()->addHours(2), 'blocked_until' => '2026-10-26', 'preparation_minutes' => 120]);
    }

    public function test_event_failure_rolls_back_hold_and_events_remain_immutable(): void
    {
        [, , $client, $car] = $this->fixture();
        $id = $this->requestFor($client, $car);
        DB::unprepared("CREATE FUNCTION test_reject_hold() RETURNS trigger AS $$ BEGIN IF NEW.action = 'hold_activated' THEN RAISE EXCEPTION 'test failure'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_hold_fail BEFORE INSERT ON reservation_events FOR EACH ROW EXECUTE FUNCTION test_reject_hold();");
        $this->hold($id)->assertServerError();
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'demande']);
        $this->assertDatabaseCount('reservation_hold_requests', 0);
        DB::unprepared('DROP TRIGGER test_hold_fail ON reservation_events; DROP FUNCTION test_reject_hold();');
        $this->hold($id)->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('reservation_hold_requests')->update(['expires_at' => now()->addYear()]);
    }

    public function test_anonymous_hold_is_denied(): void
    {
        $this->postJson('/api/v1/reservations/1/hold', [])->assertUnauthorized();
    }
}
