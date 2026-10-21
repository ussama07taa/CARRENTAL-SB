<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(bool $responsible = true): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]), Vehicle::factory()->create(['plate' => 'TEST-COCKPIT', 'make' => 'Dacia', 'model' => 'Logan'])]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);

        return [$company, $member, $vehicle];
    }

    private function loan(Vehicle $vehicle): int
    {
        return $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Banque test', 'reference' => (string) Str::uuid(), 'tracking_from' => '2026-10-01', 'schedule_confirmed' => true, 'opening_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [
            ['due_on' => '2026-09-05', 'amount_centimes' => 250000, 'opening_paid_centimes' => 250000],
            ['due_on' => '2026-10-05', 'amount_centimes' => 250050, 'opening_paid_centimes' => 50000],
            ['due_on' => '2026-12-05', 'amount_centimes' => 300000, 'opening_paid_centimes' => 0],
        ]])->assertCreated()->json('id');
    }

    private function maintenance(Vehicle $vehicle, string $mode = 'completed'): int
    {
        return $this->postJson("/api/v1/vehicles/$vehicle->id/maintenance/files", ['mode' => $mode, 'performed_on' => '2026-10-01', 'items' => [['kind' => 'oil', 'title' => 'Vidange', 'status' => $mode === 'completed' ? 'done' : 'progress', 'cost_status' => 'confirmed', 'amount_centimes' => 65000, 'completed_on' => $mode === 'completed' ? '2026-10-01' : null, 'next_on' => $mode === 'completed' ? '2026-11-10' : null]], 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'payment_due_on' => '2026-10-18', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    public function test_balances_match_source_files_without_double_counting_historical_payments(): void
    {
        [, , $vehicle] = $this->agency();
        $loan = $this->loan($vehicle);
        $this->maintenance($vehicle);
        $r = $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('summary.vehicles', 1)->assertJsonPath('summary.documents', 5)->assertJsonPath('summary.installments', 1)->assertJsonPath('summary.loan_remaining_centimes', 500050)->assertJsonPath('summary.loan_due_centimes', 200050)->assertJsonPath('summary.maintenance_remaining_centimes', 45000)->json();
        $item = collect($r['data'])->firstWhere('group', 'credit');
        $this->assertSame($loan, $item['loan_id']);
        $this->assertSame(200050, $item['amount_centimes']);
        $this->postJson("/api/v1/loans/$loan/installments/{$item['installment_id']}/payments", ['amount_centimes' => 100000, 'paid_on' => '2026-10-18', 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 200050, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/dashboard/overview?tab=credit')->assertJsonPath('data.0.amount_centimes', 100050)->assertJsonPath('summary.loan_remaining_centimes', 400050);
        $this->getJson('/api/v1/loans')->assertJsonPath('summary.remaining_centimes', 400050);
        $this->getJson('/api/v1/dashboard/overview?q=unknown')->assertJsonPath('total', 0)->assertJsonPath('summary.loan_remaining_centimes', 400050);
        $this->getJson('/api/v1/dashboard/overview?tab=credit&horizon=all')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/dashboard/overview?horizon=0&tab=maintenance')->assertJsonPath('total', 1)->assertJsonPath('data.0.category', 'payments');
    }

    public function test_distinct_garage_vehicle_count_and_maintenance_totals_are_not_summed_from_actions(): void
    {
        [, , $vehicle] = $this->agency();
        $this->maintenance($vehicle, 'garage');
        $source = $this->getJson('/api/v1/maintenance/overview?tab=all')->assertOk()->json();
        $this->getJson('/api/v1/dashboard/overview?tab=garage')->assertOk()->assertJsonPath('summary.garage_vehicles', 1)->assertJsonPath('total', 1)->assertJsonPath('summary.maintenance_remaining_centimes', $source['balance_centimes']);
        $this->getJson('/api/v1/dashboard/overview?tab=maintenance')->assertJsonPath('total', 2);
    }

    public function test_documents_use_latest_version_and_do_not_hide_current_insurance_gap(): void
    {
        [$company, $member, $vehicle] = $this->agency();
        Tenant::run($company->id, function () use ($member, $vehicle) {
            $base = ['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'path' => 'test-private.pdf'];
            VehicleDocument::factory()->create([...$base, 'expires_on' => '2026-10-17']);
            VehicleDocument::factory()->create([...$base, 'version' => 2, 'change_kind' => 'renewal', 'effective_on' => '2026-10-25', 'expires_on' => '2027-10-25']);
            VehicleDocument::factory()->create([...$base, 'kind' => 'inspection', 'effective_on' => null, 'expires_on' => '2026-10-19']);
            VehicleDocument::factory()->create([...$base, 'kind' => 'inspection', 'version' => 2, 'change_kind' => 'renewal', 'effective_on' => null, 'expires_on' => '2027-10-19']);
        });
        $r = $this->getJson('/api/v1/dashboard/overview?tab=documents')->assertOk()->assertJsonPath('total', 4)->json();
        $insurance = collect($r['data'])->firstWhere('kind', 'insurance');
        $this->assertSame('overdue', $insurance['state']);
        $this->assertSame('2026-10-17', $insurance['date']);
        $this->assertStringContainsString('2026-10-25', $insurance['reason']);
        $this->assertNull(collect($r['data'])->firstWhere('kind', 'inspection'));
    }

    public function test_cancelled_contract_is_not_an_alert_or_a_financial_commitment(): void
    {
        [, , $vehicle] = $this->agency();
        $loan = $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Test', 'reference' => 'Wrong', 'tracking_from' => '2026-10-01', 'schedule_confirmed' => true, 'opening_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [['due_on' => '2026-10-01', 'amount_centimes' => 250000, 'opening_paid_centimes' => 0]]])->assertCreated()->json('id');
        $file = $this->getJson("/api/v1/loans/$loan")->json();
        $this->postJson("/api/v1/loans/$loan/cancellations", ['reason' => 'Doublon dans le test', 'confirmed' => true, 'financial_revision' => $file['financial_revision'], 'expected_loan_remaining_centimes' => 250000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/dashboard/overview?tab=credit')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.loan_remaining_centimes', 0)->assertJsonPath('summary.installments', 0);
    }

    public function test_vignette_without_next_date_remains_visible_even_with_a_file(): void
    {
        [$company, $member, $vehicle] = $this->agency();
        Tenant::run($company->id, fn () => VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'kind' => 'tax', 'path' => 'test-private.pdf', 'effective_on' => null, 'expires_on' => null, 'tax_year' => 2026, 'paid_on' => '2026-01-10']));
        $r = $this->getJson('/api/v1/dashboard/overview?tab=documents&horizon=0')->assertOk()->assertJsonPath('total', 5)->json();
        $this->assertSame('missing', collect($r['data'])->firstWhere('kind', 'tax')['state']);
    }

    public function test_pagination_filters_and_access_are_isolated_and_fail_closed(): void
    {
        $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
        [$company] = $this->agency();
        Tenant::run($company->id, fn () => Vehicle::factory()->count(4)->create());
        $r = $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('total', 25)->assertJsonPath('last_page', 3);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->getJson('/api/v1/dashboard/overview?page=2')->assertJsonCount(12, 'data');
        $this->getJson('/api/v1/dashboard/overview?tab=wrong')->assertUnprocessable();
        $this->agency();
        $this->getJson('/api/v1/dashboard/overview')->assertJsonPath('summary.vehicles', 1)->assertJsonPath('total', 5);
        $this->agency(false);
        $this->getJson('/api/v1/dashboard/overview')->assertForbidden();
        $this->withSession(['company_id' => null])->getJson('/api/v1/dashboard/overview')->assertForbidden();
    }

    public function test_fleet_preview_uses_latest_private_cover_and_remains_independent_of_alert_filters(): void
    {
        Storage::fake('vehicle_photos');
        [$company, , $vehicle] = $this->agency();
        Tenant::run($company->id, fn () => Vehicle::factory()->count(5)->create());
        $upload = fn (string $slot, ?int $previous = null): int => $this->postJson("/api/v1/vehicles/{$vehicle->id}/photos", [
            'image' => UploadedFile::fake()->createWithContent('car.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')), 'slot' => $slot,
            'previous_id' => $previous, 'upload_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $first = $upload('cover');
        $current = $upload('cover', $first);
        $upload('interior');
        $response = $this->getJson('/api/v1/dashboard/overview?q=not-found&tab=credit')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(4, 'fleet_preview')
            ->assertJsonPath('fleet_preview.0.id', $vehicle->id)->assertJsonPath('fleet_preview.0.cover_id', $current)
            ->assertJsonPath('fleet_preview.1.cover_id', null);
        $preview = $response->json('fleet_preview.0');
        $this->assertArrayNotHasKey('path', $preview);
        $this->assertArrayNotHasKey('disk', $preview);
        $this->getJson("/api/v1/vehicles/{$vehicle->id}/photos/$current/content")->assertOk();

        [, , $otherVehicle] = $this->agency();
        $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonCount(1, 'fleet_preview')
            ->assertJsonPath('fleet_preview.0.id', $otherVehicle->id)->assertJsonPath('fleet_preview.0.cover_id', null);
        $this->getJson("/api/v1/vehicles/{$vehicle->id}/photos/$current/content")->assertNotFound();
    }

    public function test_document_categories_are_exclusive_and_each_count_matches_its_paginated_list(): void
    {
        [$company, $member, $vehicle] = $this->agency();
        Tenant::run($company->id, function () use ($member, $vehicle): void {
            $base = ['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'path' => null];
            VehicleDocument::factory()->create([...$base, 'expires_on' => '2026-10-17']);
            VehicleDocument::factory()->create([...$base, 'kind' => 'inspection', 'effective_on' => null, 'expires_on' => '2026-10-18', 'path' => 'test.pdf']);
            VehicleDocument::factory()->create([...$base, 'kind' => 'tax', 'effective_on' => null, 'expires_on' => null, 'tax_year' => 2026, 'paid_on' => '2026-01-01', 'path' => 'test.pdf']);
            foreach (['2026-10-25', '2026-10-26', '2026-11-17', '2026-11-18'] as $expires) {
                $car = Vehicle::factory()->create();
                VehicleDocument::factory()->create([...$base, 'vehicle_id' => $car->id, 'expires_on' => $expires, 'path' => 'test.pdf']);
            }
        });
        $all = $this->getJson('/api/v1/dashboard/overview?tab=documents')->assertOk()
            ->assertJsonPath('summary.documents', 24)->assertJsonPath('summary.documents_missing', 19)
            ->assertJsonPath('summary.documents_expiring', 4)->assertJsonPath('summary.documents_expired', 1)
            ->assertJsonPath('summary.urgent', 2)->json();
        $keys = [];
        foreach (['missing' => 19, 'expiring' => 4, 'expired' => 1] as $category => $count) {
            $first = $this->getJson('/api/v1/dashboard/overview?tab=documents&horizon=all&focus='.$category)
                ->assertOk()->assertJsonPath('total', $count)->assertJsonPath('summary.documents', 24)->json();
            $items = $first['data'];
            for ($page = 2; $page <= $first['last_page']; $page++) {
                $items = [...$items, ...$this->getJson('/api/v1/dashboard/overview?tab=documents&horizon=all&focus='.$category.'&page='.$page)->assertOk()->json('data')];
            }
            $this->assertCount($count, $items);
            foreach ($items as $item) {
                $this->assertSame($category, $item['document_status']);
                $keys[] = $item['key'];
            }
        }
        $this->assertCount($all['summary']['documents'], array_unique($keys));
        $expired = $this->getJson('/api/v1/dashboard/overview?focus=expired')->json('data.0');
        $this->assertStringContainsString('Justificatif non joint', $expired['reason']);
        $this->getJson('/api/v1/dashboard/overview?focus=expiring&q=TEST-COCKPIT')
            ->assertJsonPath('total', 1)->assertJsonPath('summary.documents_expiring', 4);
        $this->getJson('/api/v1/dashboard/overview?focus=unknown')->assertUnprocessable();
        $this->travelTo(now()->addDay());
        $this->getJson('/api/v1/dashboard/overview?focus=expired')->assertJsonPath('total', 2)
            ->assertJsonPath('summary.documents_expired', 2)->assertJsonPath('summary.documents_expiring', 4);
        $this->agency();
        $this->getJson('/api/v1/dashboard/overview?focus=missing')->assertJsonPath('total', 5)
            ->assertJsonPath('summary.documents_expired', 0)->assertJsonPath('summary.documents_expiring', 0);
    }

    public function test_priority_list_matches_counter_and_excludes_incomplete_documents_and_open_visits(): void
    {
        [$company, $member, $vehicle] = $this->agency();
        $this->loan($vehicle);
        $this->maintenance($vehicle, 'garage');
        Tenant::run($company->id, function () use ($member, $vehicle): void {
            $base = ['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id];
            VehicleDocument::factory()->create([...$base, 'expires_on' => '2026-10-17']);
            VehicleDocument::factory()->create([...$base, 'kind' => 'inspection', 'expires_on' => '2026-10-18', 'effective_on' => null]);
        });
        $response = $this->getJson('/api/v1/dashboard/overview?focus=urgent&horizon=all')->assertOk()
            ->assertJsonPath('summary.urgent', 4)->assertJsonPath('total', 4)->json();
        foreach ($response['data'] as $row) {
            $this->assertNotSame('garage', $row['category']);
            $this->assertTrue(in_array($row['state'], ['overdue', 'due'], true) || ($row['group'] === 'documents' && $row['date'] === '2026-10-18'));
        }
        $this->getJson('/api/v1/dashboard/overview?focus=urgent&tab=credit&horizon=all')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/dashboard/overview?focus=urgent&q=unknown')->assertJsonPath('total', 0)->assertJsonPath('summary.urgent', 4);
    }
}
