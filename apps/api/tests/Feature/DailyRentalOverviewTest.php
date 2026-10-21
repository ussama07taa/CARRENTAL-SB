<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Customer, Vehicle, Reservation};
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyRentalOverviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $company, string $status, string $start, string $end, ?string $assignment = null): int
    {
        return Tenant::run($company, function () use ($status, $start, $end, $assignment) {
            $member = CompanyMembership::factory()->create();
            return Reservation::create([
                'customer_id' => Customer::factory()->create()->id, 'vehicle_id' => Vehicle::factory()->create()->id,
                'actor_membership_id' => $member->id, 'status' => $status,
                'starts_at' => CarbonImmutable::parse($start, 'Africa/Casablanca'),
                'ends_at' => CarbonImmutable::parse($end, 'Africa/Casablanca'),
                'assignment_starts_at' => $assignment ? CarbonImmutable::parse($assignment, 'Africa/Casablanca') : null,
                'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
                'confirmed_at' => now(), 'frozen_total_centimes' => 100000, 'preparation_minutes' => 0,
                'blocked_until' => CarbonImmutable::parse($end, 'Africa/Casablanca'),
            ])->id;
        });
    }

    public function test_local_day_boundaries_current_assignment_returns_and_tenant_isolation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Africa/Casablanca'));
        $company = Company::factory()->create();
        $actor = Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);
        $first = $this->fixture($company->id, 'confirmee', '2026-10-20 00:00', '2026-10-22 12:00');
        $swap = $this->fixture($company->id, 'confirmee', '2026-10-18 12:00', '2026-10-22 12:00', '2026-10-20 15:00');
        $this->fixture($company->id, 'confirmee', '2026-10-21 00:00', '2026-10-22 12:00');
        $late = $this->fixture($company->id, 'en_location', '2026-10-18 12:00', '2026-10-20 12:00');
        $old = $this->fixture($company->id, 'en_location', '2026-10-17 12:00', '2026-10-19 23:59');
        $expected = $this->fixture($company->id, 'en_location', '2026-10-18 12:00', '2026-10-20 23:59');
        $this->fixture($company->id, 'en_location', '2026-10-18 12:00', '2026-10-21 00:00');
        foreach (['retournee','annulee','demande'] as $status) {
            $this->fixture($company->id, $status, '2026-10-20 01:00', '2026-10-20 10:00');
        }
        $other = Company::factory()->create();
        $this->fixture($other->id, 'en_location', '2026-10-18 12:00', '2026-10-20 10:00');
        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk()
            ->assertJsonPath('rentals.counts', ['departures' => 2, 'returns' => 2, 'overdue' => 2])
            ->assertJsonPath('rentals.rows.0.at', '2026-10-20T00:00');
        $this->assertSame([$first, $swap], array_column($response->json('rentals.rows'), 'id'));
        $this->assertSame([$late, $expected], array_column($this->getJson('/api/v1/dashboard/overview?rental_group=returns')->assertOk()->json('rentals.rows'), 'id'));
        $this->assertSame([$old, $late], array_column($this->getJson('/api/v1/dashboard/overview?rental_group=overdue')->assertOk()->json('rentals.rows'), 'id'));
        $this->getJson('/api/v1/dashboard/overview?rental_group=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/overview?rental_page=0')->assertUnprocessable();
    }

    public function test_daily_list_is_paginated_without_changing_counts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Africa/Casablanca'));
        $company = Company::factory()->create();
        $actor = Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);
        for ($i = 0; $i < 9; $i++) $this->fixture($company->id, 'confirmee', '2026-10-20 15:00', '2026-10-22 12:00');
        $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonCount(8, 'rentals.rows')->assertJsonPath('rentals.counts.departures', 9);
        $this->getJson('/api/v1/dashboard/overview?rental_page=99')->assertOk()->assertJsonCount(1, 'rentals.rows')->assertJsonPath('rentals.page', 2);
    }
}
