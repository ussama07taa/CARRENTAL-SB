<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetPlanningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $company = Company::factory()->create();
        [$member, $customer, $vehicle] = Tenant::run($company->id, fn (): array => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);

        return [$company, $customer, $vehicle];
    }

    private function requestFor(Customer $customer, Vehicle $vehicle, string $start, string $end): int
    {
        return $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => $start, 'ends_local' => $end, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    public function test_half_open_period_keeps_overlapping_requests_separate_and_non_blocking(): void
    {
        [, $customer, $vehicle] = $this->agency();
        $this->requestFor($customer, $vehicle, '2026-10-18T10:00', '2026-10-19T00:00');
        $id = $this->requestFor($customer, $vehicle, '2026-10-19T00:00', '2026-10-21T13:30');
        $other = $this->requestFor($customer, $vehicle, '2026-10-20T10:00', '2026-10-27T10:00');
        $this->requestFor($customer, $vehicle, '2026-10-26T00:00', '2026-10-29T10:00');
        $this->getJson('/api/v1/reservations/planning?from=2026-10-19&days=7')->assertOk()
            ->assertJsonPath('counts.requests', 2)->assertJsonPath('counts.garage', 0)->assertJsonPath('data.0.in_garage', false)
            ->assertJsonCount(2, 'data.0.events')->assertJsonPath('data.0.events.0.id', $id)->assertJsonPath('data.0.events.1.id', $other)
            ->assertJsonPath('data.0.events.0.blocks_vehicle', false)->assertJsonPath('data.0.events.0.ends_local', '2026-10-21T13:30');
        $this->assertDatabaseCount('reservation_events', 4);
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-20T10:00&ends_local=2026-10-22T10:00')->assertJsonPath('available_count', 1);
    }

    public function test_garage_has_no_invented_end_and_return_is_visible_in_history(): void
    {
        [, , $vehicle] = $this->agency();
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $id = $this->postJson($url, ['kind' => 'repair', 'title' => 'Réparation fictive', 'performed_on' => '2026-10-01', 'cost_centimes' => 0, 'paid_centimes' => 0, 'in_garage' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->getJson('/api/v1/reservations/planning?from=2026-11-01&days=30&filter=garage')->assertOk()
            ->assertJsonPath('counts.garage', 1)->assertJsonPath('data.0.events.0.ends_local', null)->assertJsonPath('data.0.events.0.blocks_vehicle', true);
        $this->postJson("$url/$id/return", ['returned_on' => '2026-10-03', 'note' => 'Retour réel de test', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/reservations/planning?from=2026-10-01&days=7')->assertOk()->assertJsonPath('counts.garage', 0)
            ->assertJsonPath('data.0.events.0.returned_on', '2026-10-03')->assertJsonPath('data.0.events.0.ends_local', '2026-10-04T00:00')->assertJsonPath('data.0.events.0.blocks_vehicle', false);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-04&days=7')->assertJsonCount(0, 'data.0.events');
    }

    public function test_a_resumed_work_visit_appears_without_duplicate_original_visit(): void
    {
        [, , $vehicle] = $this->agency();
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $work = ['kind' => 'repair', 'title' => 'Travail reporté', 'status' => 'deferred', 'reason' => 'Pièce en attente', 'cost_status' => 'unknown'];
        $id = $this->postJson("$url/files", ['mode' => 'garage', 'performed_on' => '2026-10-01', 'items' => [$work], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->postJson("$url/$id/return", ['returned_on' => '2026-10-02', 'note' => 'Reprise ultérieure', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $detail = $this->getJson("$url/$id/file")->assertOk()->json('data');
        $key = $detail['items'][0]['item_key'];
        $this->postJson("$url/$id/work/$key/follow-up", ['action' => 'start', 'revision' => $detail['revision'], 'tracking_revision' => 0, 'occurred_on' => '2026-10-10', 'note' => 'Reprise test', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/reservations/planning?from=2026-10-01&days=31')->assertOk()->assertJsonCount(2, 'data.0.events')
            ->assertJsonPath('data.0.events.0.returned_on', '2026-10-02')->assertJsonPath('data.0.events.1.starts_local', '2026-10-10T00:00')->assertJsonPath('data.0.events.1.ends_local', null)->assertJsonPath('data.0.in_garage', true);
        $this->assertDatabaseCount('vehicle_maintenance', 1);
    }

    public function test_search_filters_pagination_tenant_isolation_and_no_financial_leak(): void
    {
        [, $foreignCustomer, $foreignVehicle] = $this->agency();
        $this->requestFor($foreignCustomer, $foreignVehicle, '2026-10-20T10:00', '2026-10-22T10:00');
        [$company, $customer, $vehicle] = $this->agency();
        Tenant::run($company->id, fn () => Vehicle::factory()->count(16)->create());
        $this->requestFor($customer, $vehicle, '2026-10-20T10:00', '2026-10-22T10:00');
        $base = '/api/v1/reservations/planning?from=2026-10-19&days=7';
        $this->getJson($base)->assertOk()->assertJsonPath('counts.vehicles', 17)->assertJsonPath('counts.requests', 1)->assertJsonCount(15, 'data')->assertJsonMissingPath('balance_centimes');
        $this->getJson($base.'&page=2')->assertJsonCount(2, 'data');
        $this->getJson($base.'&filter=requests')->assertJsonPath('total', 1)->assertJsonPath('data.0.vehicle.id', $vehicle->id);
        $this->getJson($base.'&q='.urlencode($vehicle->plate))->assertJsonPath('total', 1);
        $this->getJson($base.'&q=UNMATCHED-CAR')->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->withSession(['company_id' => null])->getJson($base)->assertForbidden();
    }

    public function test_authentication_and_invalid_periods_are_rejected(): void
    {
        $this->getJson('/api/v1/reservations/planning?from=2026-10-19&days=7')->assertUnauthorized();
        $this->agency();
        $this->getJson('/api/v1/reservations/planning?from=2026-02-30&days=32')->assertUnprocessable()->assertJsonValidationErrors(['from', 'days']);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-19&days=0&filter=confirmed')->assertUnprocessable()->assertJsonValidationErrors(['days', 'filter']);

    }
}
