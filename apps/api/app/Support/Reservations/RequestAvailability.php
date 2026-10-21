<?php

namespace App\Support\Reservations;

use App\Models\Reservation;
use App\Support\Vehicles\MaintenanceOverview;
use Carbon\CarbonImmutable;

class RequestAvailability
{
    public function blockingReasons(string $start, string $end): array
    {
        $reasons = array_fill_keys($this->garageVehicles(), 'Au garage · retour non enregistré');
        foreach ($this->overdueVehicles() as $id) {
            $reasons[$id] = 'Location en retard · retour réel non enregistré';
        }
        $holds = app(TemporaryHold::class);
        $until = CarbonImmutable::parse($end)->addMinutes($holds->preparationMinutes());
        $from = CarbonImmutable::parse($start);
        if ($from <= CarbonImmutable::now()) {
            foreach (app(RentalReturn::class)->unreadyVehicles() as $id) {
                $reasons[$id] ??= 'Départ immédiat impossible · contrôlez le retour précédent et validez la voiture prête avant de créer cette demande';
            }
        }
        foreach ($holds->active() as $hold) {
            if (($hold->assignment_starts_at ?? $hold->starts_at) < $until && $hold->blocked_until > $from && ! isset($reasons[$hold->vehicle_id])) {
                $reasons[$hold->vehicle_id] = ($hold->status === 'en_location' ? 'En location' : ($hold->status === 'confirmee' ? 'Réservation confirmée' : 'Réservation temporaire')).' · DEM-'.str_pad((string) $hold->id, 6, '0', STR_PAD_LEFT).($hold->status === 'temporaire' ? ' · jusqu’au '.$hold->hold_expires_at->setTimezone(RequestPeriod::TIMEZONE)->format('d/m/Y à H:i') : ' · sur les dates demandées');
            }
        }

        return $reasons;
    }

    public function overdueVehicles(): array
    {
        return Reservation::where('status', 'en_location')->where('ends_at', '<=', now())->pluck('vehicle_id')->unique()->values()->all();
    }

    public function blockedVehicles(): array
    {
        return array_values(array_unique([...$this->garageVehicles(), ...$this->overdueVehicles()]));
    }

    public function garageVehicles(): array
    {
        return collect(app(MaintenanceOverview::class)->snapshot()['rows'])
            ->where('category', 'garage')->pluck('vehicle.id')->unique()->values()->all();
    }
}
