<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardActivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(bool $financial = true): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 00:15', 'Africa/Casablanca'));
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->create(['is_primary_responsible' => $financial]), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);

        return [$company, $member, $vehicle];
    }

    private function reservation(int $company, string $at, string $status, array $extra = []): int
    {
        return Tenant::run($company, function () use ($company, $at, $status, $extra): int {
            $member = CompanyMembership::factory()->create();
            $vehicle = Vehicle::factory()->create();
            $customer = Customer::factory()->create();
            $start = CarbonImmutable::parse($at, 'Africa/Casablanca')->utc();

            return DB::table('reservations')->insertGetId([...[
                'company_id' => $company, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id,
                'status' => $status, 'starts_at' => $start, 'ends_at' => $start->addDays(3), 'blocked_until' => $extra['ends_at'] ?? $start->addDays(3),
                'preparation_minutes' => 0, 'confirmed_at' => $start->subDay(), 'frozen_total_centimes' => 500000,
                'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            ], ...$extra]);
        });
    }

    public function test_daily_overview_uses_morocco_day_and_is_independent_of_search(): void
    {
        [$company] = $this->agency(false);
        $this->reservation($company->id, '2026-10-25 10:00', 'confirmee');
        $this->reservation($company->id, '2026-10-26 10:00', 'confirmee');
        $this->reservation($company->id, '2026-10-21 10:00', 'en_location', ['ends_at' => CarbonImmutable::parse('2026-10-25 00:05', 'Africa/Casablanca')->utc()]);
        $this->reservation($company->id, '2026-11-10 10:00', 'demande');
        $response = $this->getJson('/api/v1/dashboard/activity?q=does-not-exist&group=deadlines&window=upcoming')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('overview.departures_today', 1)
            ->assertJsonPath('overview.returns_today', 1)->assertJsonPath('overview.overdue_returns', 1)
            ->assertJsonPath('overview.pending_requests', 1)->assertJsonPath('overview.schedule_count', 2);
        $schedule=$response->json('overview.schedule');
        $this->assertSame('returns',$schedule[0]['group']);
        $this->assertSame('departures',$schedule[1]['group']);
        foreach ($response->json('overview.urgent') as $row) {
            $this->assertNotSame('finance',$row['group']);
            $this->assertArrayNotHasKey('amount_centimes',$row);
        }
        $other=Company::factory()->create();
        $this->reservation($other->id,'2026-10-25 09:00','confirmee');
        $this->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonPath('overview.departures_today',1);
    }

    public function test_website_counter_is_tenant_scoped_and_tracks_unprocessed_requests_not_views(): void
    {
        [$company] = $this->agency(false);
        $pending = $this->reservation($company->id, '2026-11-10 10:00', 'demande', ['source' => 'website', 'actor_membership_id' => null, 'website_request' => '{}']);
        $this->reservation($company->id, '2026-11-11 10:00', 'demande', ['source' => 'agency']);
        $this->reservation($company->id, '2026-11-12 10:00', 'annulee', ['source' => 'website', 'actor_membership_id' => null, 'website_request' => '{}']);
        $this->reservation($company->id, '2026-11-13 10:00', 'confirmee', ['source' => 'website', 'actor_membership_id' => null, 'website_request' => '{}']);
        $other = Company::factory()->create();
        $this->reservation($other->id, '2026-11-14 10:00', 'demande', ['source' => 'website', 'actor_membership_id' => null, 'website_request' => '{}']);
        $this->getJson('/api/v1/dashboard/activity?q=absent&page=3')->assertOk()->assertJsonPath('website_request_count', 1);
        $this->getJson('/api/v1/reservations?source=website&status=demande')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonPath('website_request_count', 1);
        DB::table('reservations')->where('id', $pending)->update(['status' => 'temporaire', 'hold_expires_at' => now()->addHours(2)]);
        $this->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonPath('website_request_count', 0);
        DB::table('reservations')->where('id', $pending)->update(['hold_expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonPath('website_request_count', 1);
    }

    public function test_public_calendar_exposes_only_active_occupancy_with_buffer_boundaries(): void
    {
        [$company] = $this->agency(false);
        config(['public_site.company_id' => $company->id]);
        $id = $this->reservation($company->id, '2026-11-10 10:00', 'confirmee', ['blocked_until' => '2026-11-13 12:00:00+00']);
        $vehicle = DB::table('reservations')->where('id', $id)->value('vehicle_id');
        DB::table('vehicles')->where('id', $vehicle)->update(['published' => true]);
        $this->reservation($company->id, '2026-11-20 10:00', 'temporaire', ['vehicle_id' => $vehicle, 'hold_expires_at' => now()->subMinute()]);
        $this->reservation($company->id, '2026-11-24 10:00', 'demande', ['vehicle_id' => $vehicle]);
        $this->reservation($company->id, '2026-11-28 10:00', 'annulee', ['vehicle_id' => $vehicle]);
        $body = $this->getJson('/api/v1/public/vehicles/'.$vehicle.'/calendar')->assertOk()->assertJsonCount(1, 'intervals')->assertJsonPath('unavailable', false)->json();
        $this->assertSame(['start', 'end', 'latest_return'], array_keys($body['intervals'][0]));
        $this->assertSame('2026-11-13T12:00', $body['intervals'][0]['end']);
        $other = Company::factory()->create();
        config(['public_site.company_id' => $other->id]);
        $this->getJson('/api/v1/public/vehicles/'.$vehicle.'/calendar')->assertNotFound();
        config(['public_site.company_id' => $company->id]);
        DB::table('vehicles')->where('id', $vehicle)->update(['published' => false]);
        $this->getJson('/api/v1/public/vehicles/'.$vehicle.'/calendar')->assertNotFound();
    }

    public function test_departures_and_returns_follow_morocco_day_boundaries_without_duplicate_overdue_returns(): void
    {
        [$company] = $this->agency(false);
        $old = $this->reservation($company->id, '2026-10-24 23:50', 'confirmee');
        $today = $this->reservation($company->id, '2026-10-25 10:00', 'confirmee');
        $tomorrow = $this->reservation($company->id, '2026-10-26 23:59', 'confirmee');
        $this->reservation($company->id, '2026-10-27 00:00', 'confirmee');
        $assignment = $this->reservation($company->id, '2026-10-24 10:00', 'confirmee', ['assignment_starts_at' => CarbonImmutable::parse('2026-10-26 11:00', 'Africa/Casablanca')->utc()]);
        $lateReturn = $this->reservation($company->id, '2026-10-20 10:00', 'en_location', ['ends_at' => CarbonImmutable::parse('2026-10-25 00:10', 'Africa/Casablanca')->utc()]);
        $this->reservation($company->id, '2026-10-25 12:00', 'annulee');
        $this->reservation($company->id, '2026-10-25 12:00', 'retournee');
        $departures = $this->getJson('/api/v1/dashboard/activity?group=departures&window=all')->assertOk()->assertJsonPath('total', 4)->json('data');
        $this->assertSame(['reservation-'.$old, 'reservation-'.$today, 'reservation-'.$assignment, 'reservation-'.$tomorrow], array_column($departures, 'key'));
        $this->assertSame(CarbonImmutable::parse('2026-10-25 10:00', 'Africa/Casablanca')->utc()->toIso8601String(), $departures[1]['date']);
        $this->assertSame('today', $departures[1]['priority']);
        $this->assertSame('/reservations?dossier='.$today.'&tab=operations', $departures[1]['href']);
        $this->getJson('/api/v1/dashboard/activity?group=departures&window=upcoming')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/dashboard/activity?group=returns&window=all')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.key', 'reservation-'.$lateReturn)->assertJsonPath('data.0.status', 'Retour en retard');
    }

    public function test_employees_get_operational_projection_without_financial_fields_counts_or_search_leaks(): void
    {
        [$company, $manager, $vehicle] = $this->agency();
        $entry = $this->maintenance($vehicle);
        $this->loan($vehicle);
        $staff = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($staff->user);
        $data = $this->getJson('/api/v1/dashboard/activity?window=all')->assertOk()->assertJsonPath('can_manage_finance', false)->json();
        $this->assertArrayNotHasKey('finance', $data['counts']);
        $this->assertStringNotContainsString('centimes', json_encode($data));
        $this->assertStringNotContainsString('Banque SECRET', json_encode($data));
        $this->assertNotContains('finance', array_column($data['data'], 'group'));
        $this->getJson('/api/v1/dashboard/activity?group=fleet')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.target.entry_id', $entry);
        $this->getJson('/api/v1/dashboard/activity?group=finance')->assertForbidden();
        $this->getJson('/api/v1/dashboard/activity?q=SECRET&window=all')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($manager->user);
        $this->getJson('/api/v1/dashboard/activity?group=finance&window=all')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('can_manage_finance', true);
    }

    public function test_all_counters_match_paginated_rows_for_each_role_and_priority_badge(): void
    {
        [$company, $manager, $vehicle] = $this->agency();
        $this->maintenance($vehicle);
        $this->loan($vehicle);
        for ($i = 0; $i < 10; $i++) {
            $this->reservation($company->id, '2026-10-25 10:00', 'confirmee');
        }
        $staff = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        foreach ([$staff, $manager] as $member) {
            $this->actingAs($member->user);
            $all = $this->getJson('/api/v1/dashboard/activity?window=all')->assertOk()->json();
            $this->assertSame($all['total'], $all['counts']['all']);
            $this->assertSame($all['total'], array_sum(array_diff_key($all['counts'], ['all' => true])));
            foreach ($all['counts'] as $group => $count) {
                $first = $this->getJson('/api/v1/dashboard/activity?window=all&group='.$group)->assertOk()->assertJsonPath('total', $count)->json();
                $keys = array_column($first['data'], 'key');
                for ($page = 2; $page <= $first['last_page']; $page++) {
                    $keys = [...$keys, ...array_column($this->getJson('/api/v1/dashboard/activity?window=all&group='.$group.'&page='.$page)->assertOk()->json('data'), 'key')];
                }
                $this->assertCount($count, $keys);
                $this->assertCount($count, array_unique($keys));
            }
            $this->getJson('/api/v1/dashboard/activity?window=priority')->assertOk()->assertJsonPath('total', $all['priority_count']);
            $this->getJson('/api/v1/dashboard/activity?window=upcoming')->assertOk()->assertJsonPath('total', $all['total'] - $all['priority_count']);
        }
    }

    private function maintenance(Vehicle $vehicle): int
    {
        return $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/maintenance/files', ['mode' => 'garage', 'performed_on' => '2026-10-24', 'garage' => 'Garage TEST', 'items' => [['kind' => 'oil', 'title' => 'Vidange TEST', 'status' => 'progress', 'cost_status' => 'confirmed', 'amount_centimes' => 65000]], 'paid_centimes' => 0, 'payment_due_on' => '2026-10-25', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function loan(Vehicle $vehicle): int
    {
        return $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Banque SECRET', 'reference' => 'ACTIVITY-TEST', 'tracking_from' => '2026-10-25', 'schedule_confirmed' => true, 'opening_confirmed' => true, 'request_key' => (string) Str::uuid(),
            'purchase' => ['seller' => 'Vendeur TEST', 'purchased_on' => '2026-10-24', 'down_payment_due_on' => '2026-10-25', 'price_centimes' => 20000000, 'down_payment_centimes' => 10000000],
            'installments' => [['due_on' => '2026-10-25', 'amount_centimes' => 5000000, 'opening_paid_centimes' => 0], ['due_on' => '2026-12-25', 'amount_centimes' => 5000000, 'opening_paid_centimes' => 0]],
        ])->assertCreated()->json('id');
    }

    public function test_finance_reuses_payables_and_reading_notifications_does_not_create_money_or_events(): void
    {
        [, , $vehicle] = $this->agency();
        $entry = $this->maintenance($vehicle);
        $loan = $this->loan($vehicle);
        $before = DB::table('vehicle_events')->count();
        $data = $this->getJson('/api/v1/dashboard/activity?group=finance&window=all')->assertOk()->assertJsonPath('total', 3)->json('data');
        $this->assertCount(3, array_unique(array_column($data, 'key')));
        $this->assertSame(15065000, array_sum(array_column($data, 'amount_centimes')));
        $this->assertSame($entry, collect($data)->firstWhere('target.type', 'maintenance')['target']['entry_id']);
        $payment = collect($data)->first(fn ($r) => $r['target']['type'] === 'loan' && ! $r['target']['purchase']);
        $this->postJson('/api/v1/loans/'.$loan.'/installments/'.$payment['target']['installment_id'].'/payments', ['amount_centimes' => 5000000, 'paid_on' => '2026-10-25', 'method' => 'transfer', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 5000000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $events = DB::table('vehicle_events')->count();
        $this->getJson('/api/v1/dashboard/activity?group=finance&window=all')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/dashboard/activity?group=finance&window=all')->assertOk()->assertJsonPath('total', 2);
        $this->assertSame($events, DB::table('vehicle_events')->count());
        $this->assertGreaterThanOrEqual($before, $events);
        $this->assertSame(1, DB::table('vehicle_loan_payments')->count());
        $this->assertSame(0, DB::table('expenses')->count());
    }

    public function test_document_renewal_does_not_hide_expired_current_cover_and_shortcut_selects_exact_document(): void
    {
        [$company, $member, $vehicle] = $this->agency(false);
        Tenant::run($company->id, function () use ($member, $vehicle): void {
            $base = ['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'path' => 'TEST.pdf'];
            VehicleDocument::factory()->create([...$base, 'expires_on' => '2026-10-24']);
            VehicleDocument::factory()->create([...$base, 'version' => 2, 'change_kind' => 'renewal', 'effective_on' => '2026-10-28', 'expires_on' => '2027-10-28']);
        });
        $data = $this->getJson('/api/v1/dashboard/activity?group=deadlines&window=all')->assertOk()->json('data');
        $insurance = collect($data)->firstWhere('target.kind', 'insurance');
        $this->assertSame('2026-10-24', $insurance['date']);
        $this->assertSame('overdue', $insurance['priority']);
        $this->assertStringContainsString('2026-10-28', $insurance['detail']);
        $this->assertSame(['type' => 'documents', 'vehicle_id' => $vehicle->id, 'kind' => 'insurance'], $insurance['target']);
        $this->assertNull(collect($data)->firstWhere('target.kind', 'registration')['date']);
    }

    public function test_pagination_and_search_preserve_global_counts_and_isolate_agencies(): void
    {
        [$company] = $this->agency(false);
        for ($i = 0; $i < 10; $i++) {
            $this->reservation($company->id, '2026-10-25 12:00', 'confirmee');
        }
        $other = Company::factory()->create();
        $this->reservation($other->id, '2026-10-25 10:00', 'confirmee');
        $first = $this->getJson('/api/v1/dashboard/activity?group=departures&page=1')->assertOk()->assertJsonPath('page', 1)->assertJsonCount(8, 'data')->assertJsonPath('counts.departures', 10)->json('data');
        $last = $this->getJson('/api/v1/dashboard/activity?group=departures&page=99')->assertOk()->assertJsonPath('page', 2)->assertJsonCount(2, 'data')->json('data');
        $this->assertEmpty(array_intersect(array_column($first, 'key'), array_column($last, 'key')));
        $this->getJson('/api/v1/dashboard/activity?group=departures&page=2')->assertOk()->assertJsonPath('page', 2)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/dashboard/activity?q=ABSENT')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('counts.departures', 10);
        $this->getJson('/api/v1/dashboard/activity?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/activity?group=wrong')->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/activity?window=wrong')->assertUnprocessable();
        $this->withSession(['company_id' => $other->id])->getJson('/api/v1/dashboard/activity')->assertForbidden();
    }

    public function test_paid_vignette_without_optional_reminder_does_not_raise_missing_date(): void
    {
        [$company,$member,$vehicle] = $this->agency(false);
        Tenant::run($company->id, fn () => VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'kind' => 'tax', 'tax_year' => 2026, 'paid_on' => '2026-10-01', 'effective_on' => null, 'expires_on' => null, 'provider' => null, 'policy_number' => null, 'path' => 'TEST.pdf']));
        $rows = $this->getJson('/api/v1/dashboard/activity?group=deadlines&window=all')->assertOk()->json('data');
        $this->assertNull(collect($rows)->firstWhere('target.kind', 'tax'));
    }

    public function test_future_renewal_does_not_hide_missing_current_attachment(): void
    {
        [$company,$member,$vehicle] = $this->agency(false);
        Tenant::run($company->id, function () use ($member, $vehicle) {
            $base = ['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id];
            VehicleDocument::factory()->create([...$base, 'effective_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'path' => null]);
            VehicleDocument::factory()->create([...$base, 'version' => 2, 'change_kind' => 'renewal', 'effective_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'path' => 'TEST.pdf']);
        });
        $rows = $this->getJson('/api/v1/dashboard/activity?group=deadlines&window=all')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('target.kind', 'insurance');
        $this->assertSame('action', $row['priority']);
        $this->assertSame('2026-12-31', $row['date']);
        $this->assertStringContainsString('Justificatif non joint', $row['detail']);
        $this->assertSame(1, collect($rows)->where('target.kind', 'insurance')->count());
    }

    public function test_missing_attachment_with_near_deadline_remains_an_action_today(): void
    {
        [$company,$member,$vehicle] = $this->agency(false);
        Tenant::run($company->id, fn () => VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'effective_on' => '2026-10-01', 'expires_on' => '2026-11-01', 'path' => null]));
        $rows = $this->getJson('/api/v1/dashboard/activity?group=deadlines&window=priority')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('target.kind', 'insurance');
        $this->assertNotNull($row);
        $this->assertSame('action', $row['priority']);
        $this->assertSame('2026-11-01', $row['date']);
    }

    public function test_authentication_and_missing_tenant_fail_closed(): void
    {
        $this->getJson('/api/v1/dashboard/activity')->assertUnauthorized();
        [, $member] = $this->agency(false);
        $this->actingAs($member->user)->withSession(['company_id' => null])->getJson('/api/v1/dashboard/activity')->assertForbidden();
    }

    public function test_shared_invoice_is_one_action_with_its_real_date_and_no_invented_due_date(): void
    {
        [$company, , $vehicle] = $this->agency();
        $second = Tenant::run($company->id, fn () => Vehicle::factory()->create());
        $id = $this->postJson('/api/v1/expenses', ['title' => 'Lavage partagé TEST', 'category' => 'wash', 'supplier' => 'Station TEST', 'invoice_reference' => 'ACTIVITY-SHARED', 'incurred_on' => '2026-10-24', 'due_on' => null, 'amount_centimes' => 120000, 'allocations' => [['vehicle_id' => $vehicle->id, 'amount_centimes' => 90000], ['vehicle_id' => $second->id, 'amount_centimes' => 30000]], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $row = $this->getJson('/api/v1/dashboard/activity?group=finance')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.amount_centimes', 120000)->json('data.0');
        $this->assertStringContainsString($vehicle->plate, $row['subject']);
        $this->assertStringContainsString($second->plate, $row['subject']);
        $this->assertSame('Dépense du', $row['date_label']);
        $this->assertSame('2026-10-24', $row['date']);
        $this->assertStringContainsString('Date de paiement à fixer', $row['detail']);
        $this->assertSame('/expenses?expense='.$id.'&action=payment', $row['href']);
    }

    public function test_expired_hold_remains_an_action_without_a_read_causing_a_state_change(): void
    {
        [$company] = $this->agency(false);
        $id = $this->reservation($company->id, '2026-10-25 12:00', 'temporaire', ['hold_expires_at' => CarbonImmutable::parse('2026-10-24 23:00', 'Africa/Casablanca')->utc()]);
        $this->getJson('/api/v1/dashboard/activity?group=requests')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 'Option expirée');
        $this->assertSame('temporaire', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('reservation_events')->count());
    }
}
