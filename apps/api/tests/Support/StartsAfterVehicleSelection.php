<?php

namespace Tests\Support;

use App\Support\Reservations\RequestVehicleReadiness;

/**
 * These suites exercise existing dossiers (payments, pickup, returns, documents).
 * Their deliberately incomplete vehicle fixtures test later gates separately.
 * Real request-entry validation is covered by RequestVehicleReadinessTest and
 * ReservationRequestTest; never use this trait in request-entry tests.
 */
trait StartsAfterVehicleSelection
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(RequestVehicleReadiness::class)
            ->shouldReceive('inspect')->andReturn(['ready' => true, 'issues' => [], 'checked_for' => null]);
    }
}
