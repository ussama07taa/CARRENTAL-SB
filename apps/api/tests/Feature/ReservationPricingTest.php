<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $priced = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00Z'));
        $company = Company::factory()->create();
        [$actor, $customer, $car] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $car->id, 'starts_local' => '2027-06-30T10:00', 'ends_local' => '2027-07-02T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $version = $priced ? $this->grid($car->id)->assertCreated()->json('id') : null;

        return [$company, $actor, $car, $id, $version];
    }

    private function grid(int $car, ?int $version = null, int $normal = 45000)
    {
        return $this->postJson('/api/v1/pricing', ['expected_version' => $version, 'request_key' => (string) Str::uuid(), 'reason' => 'Tarifs fictifs pour le test', 'configuration' => ['vehicles' => [['vehicle_id' => $car, 'daily_centimes' => $normal, 'minimum_days' => 0]], 'seasons' => [['key' => (string) Str::uuid(), 'name' => 'Juillet 2027', 'kind' => 'summer', 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-31', 'active' => true, 'rates' => [['vehicle_id' => $car, 'daily_centimes' => 60000, 'minimum_days' => 0]]]]]]);
    }

    private function agree(int $id, ?int $version, int $total = 105000, bool $calculated = true, ?int $quote = null, ?string $key = null)
    {
        return $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => $total, 'pricing_version_id' => $version, 'use_calculated_price' => $calculated, 'expected_quote_id' => $quote, 'reason' => 'Accord explicite avec le client', 'agreement_confirmed' => true, 'request_key' => $key ?? (string) Str::uuid()]);
    }

    private function pay(int $id, int $amount)
    {
        return $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => $amount, 'method' => 'especes', 'received_on' => '2026-10-19', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()]);
    }

    private function confirm(int $id, int $quote)
    {
        return $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }

    public function test_opening_calculation_is_read_only_and_preserves_existing_agreement_and_receipt(): void
    {
        [,,, $id] = $this->fixture();
        $this->agree($id, null, 270000, false)->assertCreated();
        $this->pay($id, 100000)->assertCreated();
        $events = DB::table('reservation_events')->count();
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertOk()->assertJsonPath('frozen', false)->assertJsonPath('data.total_centimes', 105000)->assertJsonPath('data.lines.0.label', 'Tarif normal')->assertJsonPath('data.lines.1.label', 'Juillet 2027')->assertJsonPath('data.period.starts_local', '2027-06-30T10:00');
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('financial.total_centimes', 270000)->assertJsonPath('financial.verified_centimes', 100000)->assertJsonPath('financial.remaining_centimes', 170000)->assertJsonPath('financial.pricing_snapshot', null);
        $this->assertDatabaseCount('reservation_quotes', 1);
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('reservation_events', $events);
    }

    public function test_adoption_recalculates_on_server_and_rejects_forged_amount_and_missing_version(): void
    {
        [,,, $id, $version] = $this->fixture();
        $this->agree($id, $version, 104999)->assertConflict()->assertJsonPath('code', 'CALCULATION_CHANGED');
        $this->agree($id, null)->assertUnprocessable();
        $this->assertDatabaseCount('reservation_quotes', 0);
        $this->agree($id, $version)->assertCreated()->assertJsonPath('financial.total_centimes', 105000)->assertJsonPath('financial.pricing_snapshot.agreement_mode', 'calculated')->assertJsonPath('financial.pricing_snapshot.total_centimes', 105000)->assertJsonPath('financial.pricing_snapshot.difference_centimes', 0)->assertJsonCount(2, 'financial.pricing_snapshot.lines');
    }

    public function test_tariff_change_requires_review_and_replay_preserves_original_agreement(): void
    {
        [,, $car, $id, $version] = $this->fixture();
        $key = (string) Str::uuid();
        $quote = $this->agree($id, $version, 100000, false, null, $key)->assertCreated()->assertJsonPath('financial.pricing_snapshot.difference_centimes', -5000)->assertJsonPath('financial.pricing_snapshot.agreement_mode', 'negotiated')->json('financial.quote_id');
        $next = $this->grid($car->id, $version, 50000)->assertCreated()->json('id');
        $this->agree($id, $version, 100000, false, null, $key)->assertOk()->assertJsonPath('replayed', true);
        $this->agree($id, $version, 105000, true, $quote)->assertConflict()->assertJsonPath('code', 'PRICING_CHANGED');
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('financial.total_centimes', 100000)->assertJsonPath('financial.pricing_snapshot.total_centimes', 105000)->assertJsonPath('financial.pricing_snapshot.version_id', $version);
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertJsonPath('data.total_centimes', 110000)->assertJsonPath('data.version_id', $next);
        $this->assertDatabaseCount('reservation_quotes', 1);
    }

    public function test_confirmation_freezes_calculation_without_requiring_latest_tariff(): void
    {
        [,, $car, $id, $version] = $this->fixture();
        $quote = $this->agree($id, $version)->assertCreated()->json('financial.quote_id');
        $this->grid($car->id, $version, 90000)->assertCreated();
        $this->pay($id, 105000)->assertCreated();
        $this->confirm($id, $quote)->assertCreated()->assertJsonPath('financial.frozen', true);
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertOk()->assertJsonPath('frozen', true)->assertJsonPath('data.total_centimes', 105000)->assertJsonPath('data.version_id', $version);
        $snapshot = json_decode(DB::table('reservation_confirmations')->first()->snapshot, true);
        $this->assertSame(105000, $snapshot['pricing_snapshot']['total_centimes']);
        $this->assertSame($version, $snapshot['pricing_snapshot']['version_id']);
        $this->agree($id, $version, 105000, true, $quote)->assertUnprocessable()->assertJsonPath('code', 'PRICE_FROZEN');
    }

    public function test_employee_can_read_but_cannot_price_and_other_company_cannot_access(): void
    {
        [$company, , , $id, $version] = $this->fixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertOk();
        $this->agree($id, $version)->assertForbidden();
        $this->agree($id, $version, 100000, false)->assertForbidden();
        $this->fixture();
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertNotFound();
        $this->agree($id, $version)->assertNotFound();
    }

    public function test_missing_tariff_is_explicit_and_manual_agreement_remains_available(): void
    {
        [,,, $id] = $this->fixture(false);
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertUnprocessable();
        $quote = $this->agree($id, null, 270000, false)->assertCreated()->assertJsonPath('financial.pricing_snapshot', null)->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        $this->confirm($id, $quote)->assertCreated();
        $this->getJson("/api/v1/reservations/$id/price-calculation")->assertOk()->assertJsonPath('frozen', true)->assertJsonPath('data', null);
    }
}
