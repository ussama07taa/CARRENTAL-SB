<?php

namespace App\Support\Vehicles;

use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Carbon\CarbonImmutable;

class VehicleDocumentAlerts
{
    public function rows(): array
    {
        $today = CarbonImmutable::today('Africa/Casablanca');
        $vehicles = Vehicle::orderBy('id')->get();
        $rows = [];
        $latest = VehicleDocument::selectRaw('DISTINCT ON (vehicle_id, kind) *')->orderBy('vehicle_id')->orderBy('kind')->orderByDesc('version')->get()->keyBy(fn ($d) => $d->vehicle_id.'-'.$d->kind);
        $current = VehicleDocument::whereIn('kind', ['insurance', 'circulation'])->where('effective_on', '<=', $today->toDateString())->selectRaw('DISTINCT ON (vehicle_id, kind) *')->orderBy('vehicle_id')->orderBy('kind')->orderByDesc('version')->get()->keyBy(fn ($d) => $d->vehicle_id.'-'.$d->kind);
        foreach ($vehicles as $v) {
            foreach (['registration' => 'Carte grise', 'insurance' => 'Assurance', 'inspection' => 'Visite technique', 'tax' => 'Vignette', 'circulation' => 'Feuille de circulation'] as $kind => $label) {
                $document = $latest->get($v->id.'-'.$kind);
                $future = in_array($kind, ['insurance', 'circulation'], true) && $document?->effective_on > $today->toDateString();
                $effective = $future ? $current->get($v->id.'-'.$kind) : $document;
                $state = $effective?->deadline['state'] ?? 'missing';
                $missingFile = $effective && ! $effective->has_file;
                if ($effective && in_array($kind, ['insurance', 'inspection', 'circulation'], true) && ! $effective->expires_on) {
                    $state = 'missing';
                }
                if (! in_array($state, ['missing', 'overdue', 'urgent', 'soon'], true) && ! $missingFile) {
                    continue;
                }
                $documentStatus = $state === 'overdue' ? 'expired' : (in_array($state, ['urgent', 'soon'], true) ? 'expiring' : 'missing');
                $reasons = array_filter([$future ? 'Renouvellement prévu le '.$document->effective_on.' · vérifier la couverture actuelle' : null, $missingFile ? 'Justificatif non joint' : null, $effective && $state === 'missing' ? 'Date d’échéance à renseigner' : null]);
                $rows[] = ['key' => 'document-'.$v->id.'-'.$kind, 'group' => 'documents', 'category' => 'document', 'document_status' => $documentStatus, 'vehicle' => $v->only(['id', 'plate', 'make', 'model']), 'title' => $label, 'kind' => $kind, 'date' => $effective?->expires_on, 'state' => in_array($state, ['recorded', 'valid'], true) ? 'missing_file' : $state, 'attention' => true, 'action' => 'document', 'amount_centimes' => null, 'reason' => $reasons ? implode(' · ', $reasons) : null, 'missing_information' => ! $effective || $missingFile || $state === 'missing'];
            }
        }

        return $rows;
    }
}
