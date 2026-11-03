<?php

namespace App\Support\Reservations;

use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Carbon\CarbonImmutable;

/** Early staff-request gate; does not replace the actual pickup checks. */
class RequestVehicleReadiness
{
    public function inspect(int $vehicleId, ?array $period = null): array
    {
        Vehicle::findOrFail($vehicleId);
        $availability = app(RequestAvailability::class);
        $issues = [];
        $day = $period ? CarbonImmutable::parse($period['starts_at'])->setTimezone(RequestPeriod::TIMEZONE)->toDateString() : now(RequestPeriod::TIMEZONE)->toDateString();
        $reason = $period ? ($availability->blockingReasons($period['starts_at'], $period['ends_at'])[$vehicleId] ?? null) : null;
        if (! $period) {
            if (in_array($vehicleId, $availability->garageVehicles(), true)) {
                $reason = 'Au garage · retour non enregistré';
            } elseif (in_array($vehicleId, $availability->maintenanceUnreadyVehicles(), true)) {
                $reason = 'Retour du garage · disponibilité à valider';
            } elseif (in_array($vehicleId, $availability->overdueVehicles(), true)) {
                $reason = 'Location en retard · retour réel non enregistré';
            }
        }
        if ($reason) {
            $issues[] = ['kind' => 'operation', 'message' => $reason];
        }
        if (in_array($vehicleId, app(RentalReturn::class)->unreadyVehicles(), true)) {
            $issues[] = ['kind' => 'operation', 'message' => 'Retour précédent · voiture pas encore déclarée prête'];
        }
        foreach (['registration' => 'Carte grise', 'insurance' => 'Assurance', 'inspection' => 'Visite technique', 'tax' => 'Vignette', 'circulation' => 'Feuille de circulation'] as $kind => $label) {
            $query = VehicleDocument::where('vehicle_id', $vehicleId)->where('kind', $kind);
            if (in_array($kind, ['insurance', 'inspection', 'circulation'], true)) {
                $query->where(fn ($q) => $q->whereNull('effective_on')->orWhere('effective_on', '<=', $day));
            }
            $doc = $query->latest('version')->first();
            $message = null;
            if (! $doc) {
                $message = $label.' : document manquant ou pas encore valable au départ.';
            } elseif (in_array($kind, ['registration', 'circulation'], true) && ! $doc->has_file) {
                $message = $label.' : justificatif manquant.';
            } elseif (in_array($kind, ['insurance', 'inspection', 'circulation'], true) && (! $doc->effective_on || ! $doc->expires_on)) {
                $message = $label.' : dates de validité à compléter.';
            } elseif ($doc->expires_on && $doc->expires_on < $day) {
                $message = $label.($kind === 'tax' ? ' : rappel enregistré dépassé.' : ' : validité expirée au départ prévu.');
            }
            if ($message) {
                $issues[] = ['kind' => 'document', 'document_kind' => $kind, 'message' => $message];
            }
        }

        return ['ready' => $issues === [], 'issues' => $issues, 'checked_for' => $day];
    }
}
