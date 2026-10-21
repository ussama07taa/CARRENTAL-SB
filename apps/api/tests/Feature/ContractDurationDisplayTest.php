<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Reservations\RentalPayment;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ContractDurationDisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_presentation_uses_saved_half_day_units_even_when_total_was_negotiated(): void
    {
        $company = Company::factory()->create();
        Tenant::run($company->id, function (): void {
            $vehicle = Vehicle::factory()->create();
            $reservation = new Reservation;
            $reservation->vehicle_id = $vehicle->id;
            $financial = \Mockery::mock(RentalPayment::class);
            $financial->shouldReceive('summary')->once()->andReturn(['payments' => [], 'verified_centimes' => 100000, 'remaining_centimes' => 0, 'pricing_snapshot' => ['agreement_mode' => 'negotiated', 'lines' => [['half_day_units' => 2, 'daily_centimes' => 55000], ['half_day_units' => 2, 'daily_centimes' => 55000], ['half_day_units' => 1, 'daily_centimes' => 55000]]]]);
            $this->app->instance(RentalPayment::class, $financial);
            $display = app(RentalContract::class)->presentation($reservation);
            $this->assertSame(5, $display['pricing_half_day_units']);
            $this->assertSame('negotiated', $display['pricing_agreement_mode']);
            $this->assertNull($display['agreed_daily_centimes']);
        });
    }

    public static function snapshot(string $language = 'fr'): array
    {
        return ['language' => $language, 'agency' => ['name' => 'Agence TEST'], 'reservation' => ['id' => 999, 'starts_at' => '2026-10-20 01:40', 'ends_at' => '2026-10-22 01:41', 'total_centimes' => 100000], 'customer' => ['first_name' => 'Client', 'last_name' => 'TEST', 'phone' => '+212600000000', 'identity_type' => 'cin', 'identity_number' => 'FICTIF', 'licence_number' => 'TEST', 'licence_expires_on' => '2030-01-01', 'address' => 'Adresse fictive'], 'vehicle' => ['make' => 'Dacia', 'model' => 'TEST', 'plate' => 'TEST-999'], 'caution' => [], 'inspection' => ['kilometers' => 15000, 'fuel_eighths' => 4, 'condition' => 'bon', 'notes' => 'TEST', 'photos' => []], 'presentation' => ['layout' => 4, 'fuel' => 'diesel', 'verified_payment_methods' => ['especes'], 'verified_centimes' => 100000, 'remaining_centimes' => 0, 'agreed_daily_centimes' => null, 'first_daily_centimes' => null, 'last_daily_centimes' => null, 'pricing_half_day_units' => 5, 'pricing_agreement_mode' => 'negotiated']];
    }

    public function test_negotiated_total_keeps_exact_minutes_and_separate_frozen_tariff_reference(): void
    {
        $snapshot = self::snapshot();
        $html = app(ContractPdf::class)->html($snapshot, 'CTR-TEST-DUREE');
        $this->assertStringContainsString('2 jours 1 min', $html);
        $this->assertStringNotContainsString('2 jours 0 h', $html);
        $this->assertStringContainsString('2,5 jours', $html);
        $this->assertStringContainsString('Référence tarifaire', $html);
        $this->assertStringContainsString('Période prévue', $html);
        $this->assertStringContainsString('1 000,00 DH', $html);
        $this->assertSame(100000, $snapshot['reservation']['total_centimes']);
    }

    public function test_calculated_and_arabic_labels_and_hour_minute_remainder_are_clear(): void
    {
        $snapshot = self::snapshot('ar');
        $snapshot['presentation']['pricing_agreement_mode'] = 'calculated';
        $snapshot['reservation']['ends_at'] = '2026-10-22 03:43';
        $html = app(ContractPdf::class)->html($snapshot, 'CTR-TEST-AR');
        $this->assertStringContainsString('2 يوم 2 ساعة 3 دقيقة', $html);
        $this->assertStringContainsString('2.5 يوم', $html);
        $this->assertStringContainsString('المدة المحتسبة', $html);
    }

    public function test_old_snapshot_does_not_invent_a_billed_duration_or_hide_a_minute(): void
    {
        $snapshot = self::snapshot();
        unset($snapshot['presentation']);
        $html = app(ContractPdf::class)->html($snapshot, 'CTR-TEST-OLD');
        $this->assertStringContainsString('2 jours 1 min', $html);
        $this->assertStringNotContainsString('2,5 jours', $html);
        $this->assertStringNotContainsString('Référence tarifaire', $html);
    }

    public function test_exact_days_do_not_add_a_phantom_half_day(): void
    {
        $snapshot = self::snapshot();
        $snapshot['reservation']['ends_at'] = '2026-10-22 01:40';
        $snapshot['presentation']['pricing_half_day_units'] = 4;
        $html = app(ContractPdf::class)->html($snapshot, 'CTR-TEST-EXACT');
        $this->assertStringContainsString('<span class="number">2 jours</span>', $html);
        $this->assertStringNotContainsString('2,5 jours', $html);
    }
}
