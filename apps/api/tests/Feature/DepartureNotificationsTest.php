<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepartureNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function reservation(int $company, string $at, string $status = 'confirmee'): int
    {
        return Tenant::run($company, function () use ($company, $at, $status): int {
            $member = CompanyMembership::factory()->create();
            $vehicle = Vehicle::factory()->create();
            $customer = Customer::factory()->create();
            $start = CarbonImmutable::parse($at, 'Africa/Casablanca')->utc();

            return DB::table('reservations')->insertGetId([
                'company_id' => $company, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
                'actor_membership_id' => $member->id, 'status' => $status,
                'starts_at' => $start, 'ends_at' => $start->addDays(3),
                'blocked_until' => $start->addDays(3), 'preparation_minutes' => 0,
                'confirmed_at' => $start->subDay(), 'frozen_total_centimes' => 10000,
                'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            ]);
        });
    }

    public function test_employee_sees_only_confirmed_departures_through_tomorrow_in_morocco_with_dates_and_no_finance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 00:15', 'Africa/Casablanca'));
        $company = Company::factory()->create();
        $member = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $old = $this->reservation($company->id, '2026-10-21 23:45');
        $today = $this->reservation($company->id, '2026-10-22 11:30');
        $tomorrow = $this->reservation($company->id, '2026-10-23 23:59');
        $this->reservation($company->id, '2026-10-24 00:00');
        foreach (['demande', 'annulee', 'en_location', 'retournee'] as $status) {
            $this->reservation($company->id, '2026-10-22 12:00', $status);
        }
        $other = Company::factory()->create();
        $this->reservation($other->id, '2026-10-22 10:00');
        $before = DB::table('reservations')->count();
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $result = $this->getJson('/api/v1/reservations/departure-notifications')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('urgent', 2)
            ->assertJsonPath('data.0.id', $old)->assertJsonPath('data.0.state', 'overdue')
            ->assertJsonPath('data.1.id', $today)->assertJsonPath('data.1.state', 'due')
            ->assertJsonPath('data.2.id', $tomorrow)->assertJsonPath('data.2.state', 'tomorrow')->json();
        $this->assertSame('2026-10-22T11:30', $result['data'][1]['at']);
        $this->assertSame('/reservations?dossier='.$today.'&tab=operations', $result['data'][1]['href']);
        $this->assertArrayNotHasKey('frozen_total_centimes', $result['data'][0]);
        $this->assertSame($before, DB::table('reservations')->count());
        $this->assertSame(0, DB::table('reservation_events')->count());
        $this->getJson('/api/v1/dashboard/overview')->assertForbidden();
    }

    public function test_authentication_tenant_context_and_pagination_are_enforced(): void
    {
        $this->getJson('/api/v1/reservations/departure-notifications')->assertUnauthorized();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Africa/Casablanca'));
        $company = Company::factory()->create();
        $member = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        for ($i = 0; $i < 8; $i++) {
            $this->reservation($company->id, '2026-10-22 12:00');
        }
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $one = $this->getJson('/api/v1/reservations/departure-notifications')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('total', 8)->json('data');
        $two = $this->getJson('/api/v1/reservations/departure-notifications?page=99')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('page', 2)->json('data');
        $this->assertEmpty(array_intersect(array_column($one, 'id'), array_column($two, 'id')));
        $this->getJson('/api/v1/reservations/departure-notifications?page=0')->assertUnprocessable();
        $other = Company::factory()->create();
        $this->withSession(['company_id' => $other->id])->getJson('/api/v1/reservations/departure-notifications')->assertForbidden();
    }
}
