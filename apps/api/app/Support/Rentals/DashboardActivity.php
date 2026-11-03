<?php

namespace App\Support\Rentals;

use App\Models\Reservation;
use App\Support\Finance\PayablesOverview;
use App\Support\Vehicles\MaintenanceOverview;
use App\Support\Vehicles\VehicleDocumentAlerts;
use Carbon\CarbonImmutable;

class DashboardActivity
{
    public function read(array $input, bool $financial): array
    {
        $now = CarbonImmutable::now('Africa/Casablanca');
        $today = $now->toDateString();
        $until = $now->addDays(30)->toDateString();
        $rows = $this->rentals($now);
        $payables = $financial ? app(PayablesOverview::class)->query()->where(fn ($q) => $q->whereNull('due_on')->orWhere('due_on', '<=', $until))->get() : collect();
        $maintenance = app(MaintenanceOverview::class)->snapshot();
        foreach ($maintenance['rows'] as $item) {
            if ($item['category'] === 'pricing' && $payables->contains(fn ($payable) => $payable->source === 'maintenance' && (int) $payable->source_id === $item['entry_id'])) {
                continue;
            }
            $row = $this->maintenance($item, $today, $until, $financial);
            if ($row !== null && $row['date'] === null && isset($item['km'])) {
                $row['date_note'] = 'Rappel au kilométrage · aucune date fixée';
            }
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        foreach (app(VehicleDocumentAlerts::class)->rows() as $item) {
            $priority = $item['state'] === 'overdue' ? 'overdue' : ($item['missing_information'] ? 'action' : ($item['date'] === $today ? 'today' : 'upcoming'));
            $rows[] = $this->row($item['key'], 'deadlines', $item['title'], $item['vehicle'], $item['date'], 'Valable jusqu’au', $priority,
                $item['state'] === 'overdue' ? 'Document expiré' : ($item['date'] === $today ? 'Expire aujourd’hui' : ($priority === 'action' ? 'À compléter' : 'À renouveler')),
                $item['reason'] ?: ($priority === 'action' ? 'Compléter les informations ou joindre le justificatif.' : 'Vérifier le document et préparer son renouvellement.'),
                'Ouvrir le document', ['type' => 'documents', 'vehicle_id' => $item['vehicle']['id'], 'kind' => $item['kind']]);
        }
        $groups = app(DailyRentalOverview::class)->followupGroups($financial);
        foreach ($groups as $group => $items) {
            foreach ($items as $item) {
                $rows[] = [...$this->row('rental-'.$group.'-'.$item['key'], $group === 'readiness' ? 'fleet' : 'finance',
                    $group === 'readiness' ? 'Terminer le retour' : ($group === 'pending' ? 'Réception à vérifier' : 'Compte client à régulariser'),
                    null, $item['date'], $group === 'readiness' ? 'Retour enregistré le' : 'Dossier créé le', 'action', 'À traiter', $item['reason'], $item['action']),
                    'subject' => $item['vehicle'].' · '.$item['plate'], 'context' => $item['customer'].' · '.$item['reference'], 'href' => $item['href'],
                    ...($financial && $item['amount_centimes'] !== null ? ['amount_centimes' => $item['amount_centimes']] : [])];
            }
        }
        if ($financial) {
            foreach ($payables as $item) {
                $vehicle = $item->vehicle ? json_decode($item->vehicle, true) : null;
                $allocations = json_decode($item->allocations, true);
                $sharedVehicles = collect($allocations)->pluck('vehicle')->filter()->map(fn ($v) => trim($v['make'].' '.$v['model']).' · '.$v['plate'])->all();
                $target = match ($item->source) {
                    'loan', 'purchase' => ['type' => 'loan', 'id' => (int) $item->source_id, 'installment_id' => $item->installment_id ? (int) $item->installment_id : null, 'purchase' => $item->source === 'purchase'],
                    'maintenance' => ['type' => 'maintenance', 'vehicle_id' => $vehicle['id'], 'entry_id' => (int) $item->source_id, 'operation' => $item->cost_final ? 'pay' : 'price', 'odometer' => $item->odometer ? json_decode($item->odometer, true) : null],
                    default => null,
                };
                $priority = $item->cost_final ? $this->datePriority($item->due_on, $today) : 'action';
                $rows[] = [...$this->row('payable-'.$item->key, 'finance', $item->source === 'loan' ? 'Mensualité de crédit' : ($item->source === 'purchase' ? 'Apport initial' : $item->title),
                    $vehicle, $item->due_on ?? $item->date, $item->due_on ? 'À payer le' : 'Dépense du', $priority, $item->cost_final ? $this->dueLabel($priority) : 'Prix à confirmer',
                    trim($item->supplier.' · '.$item->reference, ' ·').($item->due_on ? '' : ' · Date de paiement à fixer'), $item->cost_final ? 'Ouvrir le paiement' : 'Compléter le prix', $target),
                    ...(! $vehicle && $sharedVehicles ? ['subject' => 'Facture partagée · '.implode(' / ', $sharedVehicles)] : []),
                    'amount_centimes' => (int) $item->balance_centimes,
                    'amount_label' => $item->cost_final ? 'Reste à payer' : 'Reste connu · prix provisoire',
                    'href' => $item->source === 'manual' ? '/expenses?expense='.$item->source_id.'&action=payment' : null];
            }
        }
        $rank = ['overdue' => 0, 'today' => 1, 'action' => 2, 'upcoming' => 3];
        usort($rows, fn ($a, $b) => [$rank[$a['priority']], $a['date'] ?? '9999', $a['key']] <=> [$rank[$b['priority']], $b['date'] ?? '9999', $b['key']]);
        $counts = array_fill_keys(['all', 'departures', 'returns', 'fleet', 'deadlines', 'requests', ...($financial ? ['finance'] : [])], 0);
        $priorityCount = 0;
        foreach ($rows as $row) {
            $counts['all']++;
            $counts[$row['group']]++;
            if ($row['priority'] !== 'upcoming') {
                $priorityCount++;
            }
        }
        // Stable daily overview: independent of list filters, pagination and search.
        $schedule = array_values(array_filter($rows, fn ($row) => in_array($row['group'], ['departures', 'returns'], true)
            && $row['date'] && CarbonImmutable::parse($row['date'])->setTimezone('Africa/Casablanca')->toDateString() === $today));
        usort($schedule, fn ($a, $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
        $urgent = array_values(array_filter($rows, fn ($row) => in_array($row['priority'], ['overdue', 'today'], true)));
        usort($urgent, fn ($a, $b) => [in_array($a['group'], ['departures', 'returns']) ? 0 : 1, $rank[$a['priority']], $a['date'] ?? '9999', $a['key']]
            <=> [in_array($b['group'], ['departures', 'returns']) ? 0 : 1, $rank[$b['priority']], $b['date'] ?? '9999', $b['key']]);
        $overview = [
            'departures_today' => count(array_filter($schedule, fn ($row) => $row['group'] === 'departures')),
            'returns_today' => count(array_filter($schedule, fn ($row) => $row['group'] === 'returns')),
            'overdue_returns' => count(array_filter($rows, fn ($row) => $row['group'] === 'returns' && $row['priority'] === 'overdue')),
            'pending_requests' => count(array_filter($rows, fn ($row) => $row['group'] === 'requests' && $row['status'] === 'Demande à suivre')),
            'urgent_count' => count($urgent), 'urgent' => array_slice($urgent, 0, 4),
            'schedule_count' => count($schedule), 'schedule' => array_slice($schedule, 0, 12),
        ];
        $notificationCounts = null;
        if (! empty($input['notifications'])) {
            $rows = $this->notificationRows($rows);
            $notificationCounts = ['urgent' => 0, 'action' => 0, 'upcoming' => 0];
            foreach ($rows as $row) {
                $bucket = in_array($row['priority'], ['overdue', 'today'], true) ? 'urgent' : $row['priority'];
                $notificationCounts[$bucket]++;
            }
            // Badge describes urgent actions and requests to process, not unread messages.
            $priorityCount = $notificationCounts['urgent'] + count(array_filter($rows, fn ($row) => $row['group'] === 'requests' && $row['priority'] === 'action'));
        }
        $q = mb_strtolower(trim($input['q'] ?? ''));
        $filtered = array_values(array_filter($rows, function (array $row) use ($input, $q): bool {
            $window = $input['window'] ?? 'priority';

            return (($input['group'] ?? 'all') === 'all' || $row['group'] === $input['group'])
                && ($window === 'all' || match ($window) { 'priority' => $row['priority'] !== 'upcoming', 'urgent' => in_array($row['priority'], ['overdue', 'today'], true), default => $row['priority'] === $window })
                && ($q === '' || str_contains(mb_strtolower(implode(' ', [$row['title'], $row['subject'], $row['context'], $row['detail']])), $q));
        }));
        $last = max(1, (int) ceil(count($filtered) / 8));
        $page = min((int) ($input['page'] ?? 1), $last);

        return ['data' => array_slice($filtered, ($page - 1) * 8, 8), 'total' => count($filtered), 'page' => $page, 'last_page' => $last,
            'notification_counts' => $notificationCounts, 'overview' => $overview, 'counts' => $counts, 'priority_count' => $priorityCount,
            'website_request_count' => Reservation::where('source', 'website')->where(fn ($q) => $q->where('status', 'demande')->orWhere(fn ($held) => $held->where('status', 'temporaire')->where('hold_expires_at', '<=', $now)))->count(), 'can_manage_finance' => $financial,
            'today' => $today, 'generated_at' => $now->toIso8601String()];
    }

    private function notificationRows(array $rows): array
    {
        $result = [];
        $documents = [];
        foreach ($rows as $row) {
            if (($row['target']['type'] ?? null) === 'documents' && $row['priority'] === 'action') {
                $documents[$row['vehicle']['id']][] = $row;
            } else {
                $result[] = $row;
            }
        }
        foreach ($documents as $vehicleId => $items) {
            $row = $items[0];
            $row['key'] = 'vehicle-documents-'.$vehicleId;
            $row['title'] = count($items).' document'.(count($items) > 1 ? 's' : '').' à compléter';
            $row['detail'] = implode(' · ', array_column($items, 'title'));
            $row['document_details'] = array_map(fn ($item) => ['title' => $item['title'], 'detail' => $item['detail']], $items);
            $row['date'] = null;
            $row['action'] = 'Compléter les documents';
            unset($row['target']['kind']);
            $result[] = $row;
        }
        $rank = ['overdue' => 0, 'today' => 1, 'action' => 2, 'upcoming' => 3];
        usort($result, fn ($a, $b) => [$rank[$a['priority']], $a['date'] ?? '9999', $a['key']] <=> [$rank[$b['priority']], $b['date'] ?? '9999', $b['key']]);

        return $result;
    }

    private function rentals(CarbonImmutable $now): array
    {
        $rows = [];
        $end = $now->startOfDay()->addDays(2)->utc();
        $records = Reservation::where(function ($q) use ($end): void {
            $q->where(fn ($q) => $q->where('status', 'confirmee')->whereRaw('COALESCE(assignment_starts_at, starts_at) < ?', [$end]))
                ->orWhere(fn ($q) => $q->where('status', 'en_location')->where('ends_at', '<', $end))
                ->orWhereIn('status', ['demande', 'temporaire']);
        })->join('customers as c', fn ($j) => $j->on('c.id', '=', 'reservations.customer_id')->on('c.company_id', '=', 'reservations.company_id'))
            ->join('vehicles as v', fn ($j) => $j->on('v.id', '=', 'reservations.vehicle_id')->on('v.company_id', '=', 'reservations.company_id'))
            ->get(['reservations.*', 'c.first_name', 'c.last_name', 'v.make', 'v.model', 'v.plate']);
        foreach ($records as $record) {
            $vehicle = ['id' => $record->vehicle_id, 'make' => $record->make, 'model' => $record->model, 'plate' => $record->plate];
            $request = in_array($record->status, ['demande', 'temporaire'], true);
            $return = $record->status === 'en_location';
            $at = $request ? ($record->hold_expires_at ?? $record->created_at) : ($return ? $record->ends_at : ($record->assignment_starts_at ?? $record->starts_at));
            $priority = $request ? 'action' : ($at <= $now ? 'overdue' : ($at->setTimezone('Africa/Casablanca')->isSameDay($now) ? 'today' : 'upcoming'));
            $held = $record->status === 'temporaire';
            $rows[] = [...$this->row('reservation-'.$record->id, $request ? 'requests' : ($return ? 'returns' : 'departures'),
                $request ? ($held ? 'Vérifier le dossier temporaire' : 'Traiter la demande') : ($return ? 'Réceptionner la voiture' : 'Préparer le départ'), $vehicle,
                $at?->toIso8601String(), $request ? ($record->hold_expires_at ? 'Fin de l’option' : 'Demande reçue le') : ($return ? 'Retour prévu' : 'Départ prévu'),
                $priority, $request ? ($held ? ($record->hold_expires_at <= $now ? 'Option expirée' : 'Option en cours') : 'Demande à suivre') : ($priority === 'overdue' ? ($return ? 'Retour en retard' : 'Départ à vérifier') : ($priority === 'today' ? 'Aujourd’hui' : 'Demain')),
                $request ? 'Reprendre le dossier et vérifier la prochaine étape.' : ($return ? ($priority === 'overdue' ? 'Contacter le client et vérifier les réservations suivantes.' : 'Préparer le contrôle du retour.') : ($priority === 'overdue' ? 'Vérifier la venue du client et la remise des clés.' : 'Prévoir le lavage si nécessaire, puis vérifier les conditions de départ.')),
                $request ? 'Ouvrir la demande' : ($return ? 'Ouvrir le retour' : 'Préparer la voiture')),
                'context' => trim($record->first_name.' '.$record->last_name).' · DEM-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
                'href' => '/reservations?'.http_build_query(['dossier' => $record->id, ...($request ? [] : ['tab' => 'operations'])])];
        }

        return $rows;
    }

    private function maintenance(array $item, string $today, string $until, bool $financial): ?array
    {
        if (in_array($item['category'], ['cancelled', 'payments'], true) || (! $financial && in_array($item['category'], ['pricing', 'credits'], true))) {
            return null;
        }
        if (in_array($item['category'], ['reminder', 'deferred'], true) && ! $item['attention'] && ($item['date'] === null || $item['date'] > $until)) {
            return null;
        }
        $garage = in_array($item['category'], ['garage', 'readiness'], true);
        $finance = in_array($item['category'], ['pricing', 'credits'], true);
        $priority = $garage || $finance || ($item['needs_odometer'] ?? false) ? 'action' : (in_array($item['state'], ['overdue', 'due'], true) ? ($item['state'] === 'overdue' ? 'overdue' : 'today') : $this->datePriority($item['date'], $today));
        $title = match ($item['category']) {
            'garage' => 'Suivre le passage au garage', 'readiness' => 'Contrôler la voiture après le garage',
            'pricing' => 'Compléter le prix de l’entretien', 'credits' => 'Régulariser l’avance au garage',
            default => $item['title'],
        };
        $detail = $garage || $finance ? $item['title'] : ($item['reason'] ?? 'Vérifier l’entretien et programmer l’intervention.');
        if (isset($item['km'])) {
            $detail .= ' · À '.number_format($item['km'], 0, ',', ' ').' km';
        }
        if ($item['needs_odometer'] ?? false) {
            $detail .= ' · Compteur à renseigner';
        } elseif (isset($item['remaining_km']) && $item['remaining_km'] <= 0) {
            $detail .= ' · Seuil kilométrique atteint';
        }
        $label = match ($item['category']) {
            'garage' => 'Au garage', 'readiness' => 'Prêt à contrôler', 'pricing' => 'Prix à confirmer',
            'credits' => 'À régulariser', default => $this->dueLabel($priority),
        };

        return $this->row('maintenance-'.$item['key'], $finance ? 'finance' : ($garage ? 'fleet' : 'deadlines'), $title, $item['vehicle'], $item['date'],
            $item['category'] === 'garage' ? 'Au garage depuis le' : ($item['category'] === 'readiness' ? 'Retour du garage le' : 'Prévu le'), $priority, $label, $detail,
            match ($item['action']) {
                'ready' => 'Contrôler la voiture', 'price' => 'Compléter le prix', 'create' => 'Préparer l’entretien', default => 'Ouvrir le suivi'
            },
            ['type' => 'maintenance', 'vehicle_id' => $item['vehicle']['id'], 'entry_id' => $item['entry_id'], 'operation' => match ($item['action']) {
                'ready' => 'ready', 'price' => 'price', 'return' => 'return', default => null
            },
                'followup_key' => $item['action'] === 'followup' ? ($item['item_key'] ?? null) : null, 'kind' => $item['kind'] ?? null, 'odometer' => $item['odometer']]);
    }

    private function datePriority(?string $date, string $today): string
    {
        return $date === null ? 'action' : ($date < $today ? 'overdue' : ($date === $today ? 'today' : 'upcoming'));
    }

    private function dueLabel(string $priority): string
    {
        return ['overdue' => 'En retard', 'today' => 'Aujourd’hui', 'action' => 'À traiter', 'upcoming' => 'À venir'][$priority];
    }

    private function row(string $key, string $group, string $title, ?array $vehicle, ?string $date, string $dateLabel, string $priority, string $status, string $detail, string $action, ?array $target = null): array
    {
        return ['key' => $key, 'group' => $group, 'title' => $title, 'subject' => $vehicle ? trim($vehicle['make'].' '.$vehicle['model']).' · '.$vehicle['plate'] : 'Agence',
            'vehicle' => $vehicle, 'context' => '', 'date' => $date, 'date_label' => $dateLabel, 'priority' => $priority, 'status' => $status, 'detail' => $detail, 'action' => $action, 'target' => $target, 'href' => null];
    }
}
