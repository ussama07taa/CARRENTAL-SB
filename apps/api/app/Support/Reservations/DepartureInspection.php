<?php

namespace App\Support\Reservations;

use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DepartureInspection
{
    public function summary(Reservation $record): array
    {
        $ready = Schema::hasTable('rental_departure_inspections');
        $vehicle = Vehicle::findOrFail($record->vehicle_id);
        $latest = $ready ? DB::table('rental_departure_inspections')->where('company_id', Tenant::id())->where('reservation_id', $record->id)->where('vehicle_id', $record->vehicle_id)->when(app(RentalVehicleSwap::class)->ready(), fn ($q) => $q->where('swap_id', $record->active_swap_id))->latest('id')->first() : null;
        $photos = $latest ? DB::table('rental_inspection_photos')->where('company_id', Tenant::id())->where('inspection_id', $latest->id)->orderBy('id')->get(['id', 'caption', 'mime', 'size']) : [];
        $stale = $latest && ((int) $latest->vehicle_id !== $vehicle->id || (int) $latest->kilometers !== $vehicle->current_odometer || $latest->inspected_on < substr((string) $vehicle->current_odometer_date, 0, 10));

        return ['ready' => $ready, 'vehicle' => $vehicle->only(['id', 'version', 'current_odometer', 'current_odometer_date']), 'latest' => $latest ? ['id' => $latest->id, 'previous_id' => $latest->previous_id, 'inspected_on' => $latest->inspected_on, 'kilometers' => (int) $latest->kilometers, 'fuel_eighths' => (int) $latest->fuel_eighths, 'condition' => $latest->condition, 'notes' => $latest->notes, 'reason' => $latest->reason, 'created_at' => $latest->created_at, 'photos' => $photos] : null, 'stale' => (bool) $stale, 'completed' => $latest !== null && ! $stale && $latest->condition !== 'anomalie_bloquante', 'blocking_anomaly' => $latest?->condition === 'anomalie_bloquante'];
    }
}
