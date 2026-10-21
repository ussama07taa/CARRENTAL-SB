<?php

namespace App\Support\Rentals;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DailyRentalOverview
{
    public function followups(string $group = 'readiness', int $page = 1): array
    {
        $company = \App\Support\Tenancy\Tenant::id();
        $table = fn ($name) => \Illuminate\Support\Facades\DB::table($name)->where('company_id', $company);
        $groups = ['readiness' => [], 'settlement' => [], 'pending' => []];
        $returns = $table('rental_returns')->whereIn('id', $table('rental_returns')->selectRaw('MAX(id)')->groupBy('vehicle_id'))->get();
        $inspections = $table('rental_return_inspections')->whereIn('return_id', $returns->pluck('id'))->orderByDesc('id')->get()->unique('return_id')->keyBy('return_id');
        $approvals = $table('rental_return_readiness')->whereIn('inspection_id', $inspections->pluck('id'))->pluck('inspection_id')->all();
        $out = Reservation::where('status', 'en_location')->pluck('vehicle_id')->all();
        $garage = app(\App\Support\Reservations\RequestAvailability::class)->garageVehicles();
        $vehicles = \App\Models\Vehicle::whereIn('id', $returns->pluck('vehicle_id'))->get()->keyBy('id');
        $returnRecords = Reservation::whereIn('id', $returns->pluck('reservation_id'))->get()->keyBy('id');
        $customers = \App\Models\Customer::whereIn('id', $returnRecords->pluck('customer_id'))->get()->keyBy('id');
        foreach ($returns as $return) {
            $inspection = $inspections->get($return->id);
            $record = $returnRecords->get($return->reservation_id);
            $vehicle = $vehicles->get($return->vehicle_id);
            if (! $record || ! $vehicle || ! $customers->has($record->customer_id) || in_array($vehicle->id, $out, true)) continue;
            $record->setRelation('customer', $customers->get($record->customer_id));
            $approved = $inspection && in_array($inspection->id, $approvals, true);
            if ($approved && $inspection->signature_state === 'signed' && ! in_array($vehicle->id, $garage, true)) continue;
            $reason = ! $inspection ? 'Contrôle du retour à effectuer' : (in_array($vehicle->id, $garage, true) ? 'Passage au garage encore ouvert' : ($inspection->condition === 'anomalie_bloquante' ? 'Anomalie bloquante à résoudre' : ($inspection->signature_state !== 'signed' ? 'Constat de retour non signé · suivi à terminer' : 'Disponibilité à valider après contrôle')));
            $groups['readiness'][] = $this->followupRow($record, $vehicle, $reason, 'Revoir le retour', 'operations', ['return_id' => $return->id]);
        }
        // Batch account calculations use the same ledger as the rental file; no money is changed.
        Reservation::orderBy('id')->chunkById(200, function ($records) use (&$groups, $table): void {
            $customers = \App\Models\Customer::whereIn('id', $records->pluck('customer_id'))->get()->keyBy('id');
            $vehicles = \App\Models\Vehicle::whereIn('id', $records->pluck('vehicle_id'))->get()->keyBy('id');
            $briefs = app(\App\Support\Reservations\RentalSettlement::class)->briefs($records);
            $pendingByScope = [];
            foreach (['rent' => ['rental_payments', 'rental_payment_verifications', 'rent_payment_id'], 'fuel' => ['rental_fuel_payments', 'rental_fuel_verifications', 'fuel_payment_id']] as $scope => [$payments, $verifications, $column]) {
                $void = $table('rental_settlement_entries')->whereIn('reservation_id', $records->pluck('id'))->where('kind', 'payment_void')->where('scope', $scope)->whereNotNull($column)->pluck($column);
                $verified = $table($verifications)->whereIn('reservation_id', $records->pluck('id'))->pluck('payment_id');
                $pendingByScope[$scope] = $table($payments)->whereIn('reservation_id', $records->pluck('id'))->whereNotIn('id', $void)->whereNotIn('id', $verified)->get()->groupBy('reservation_id');
            }
            foreach ($records as $record) {
                $account = $briefs[$record->id] ?? null;
                $record->setRelation('customer', $customers->get($record->customer_id));
                $record->setRelation('vehicle', $vehicles->get($record->vehicle_id));
                if (! $account || ! $record->customer || ! $record->vehicle) continue;
                if ($account['pending_centimes'] > 0) {
                    $rent = (int) $pendingByScope['rent']->get($record->id, collect())->sum('amount_centimes');
                    $fuel = (int) $pendingByScope['fuel']->get($record->id, collect())->sum('amount_centimes');
                    foreach (['rent' => $rent, 'fuel' => $fuel, 'deposit' => max(0, $account['pending_centimes'] - $rent - $fuel)] as $scope => $amount) {
                        if ($amount <= 0) continue;
                        $label = ['rent' => 'Loyer', 'fuel' => 'Carburant', 'deposit' => 'Caution'][$scope];
                        $row = $this->followupRow($record, $record->vehicle, $label.' · réception à vérifier, non comptée comme reçue', 'Vérifier la réception', $scope === 'fuel' ? 'operations' : 'payments', ['payment_view' => $scope === 'rent' ? 'receipts' : 'settlement'], $amount);
                        $row['key'] .= '-'.$scope;
                        $groups['pending'][] = $row;
                    }
                }
                if (in_array($record->status, ['retournee', 'annulee'], true) && ! $account['settled']) {
                    $reasons = array_filter([$account['deposit_held_centimes'] > 0 ? 'Caution encore détenue / bloquée' : null, $account['credit_centimes'] > 0 ? 'Crédit client à traiter' : null, $account['remaining_centimes'] > 0 ? 'Montant encore dû' : null]);
                    if ($reasons) $groups['settlement'][] = $this->followupRow($record, $record->vehicle, implode(' · ', $reasons), 'Revoir le compte', 'payments', ['payment_view' => 'settlement']);
                }
            }
        });
        $counts = array_map('count', $groups);
        $last = max(1, (int) ceil($counts[$group] / 6));
        $page = min(max(1, $page), $last);
        return ['counts' => $counts, 'group' => $group, 'page' => $page, 'last_page' => $last, 'rows' => array_slice($groups[$group], ($page - 1) * 6, 6)];
    }

    private function followupRow($record, $vehicle, string $reason, string $action, string $tab, array $extra = [], ?int $amount = null): array
    {
        return ['key' => $record->id.'-'.$vehicle->id, 'reference' => 'DEM-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT), 'customer' => trim($record->customer->first_name.' '.$record->customer->last_name), 'vehicle' => trim($vehicle->make.' '.$vehicle->model), 'plate' => $vehicle->plate, 'reason' => $reason, 'action' => $action, 'amount_centimes' => $amount, 'href' => '/reservations?'.http_build_query(['dossier' => $record->id, 'tab' => $tab, ...$extra])];
    }

    public function read(string $group = 'departures', int $page = 1): array
    {
        $now = CarbonImmutable::now('Africa/Casablanca');
        $start = $now->startOfDay()->utc();
        $end = $now->startOfDay()->addDay()->utc();
        $queries = [
            'departures' => Reservation::where('reservations.status', 'confirmee')->whereRaw('COALESCE(assignment_starts_at, starts_at) >= ? AND COALESCE(assignment_starts_at, starts_at) < ?', [$start, $end]),
            'returns' => Reservation::where('reservations.status', 'en_location')->where('ends_at', '>=', $start)->where('ends_at', '<', $end),
            'overdue' => Reservation::where('reservations.status', 'en_location')->where('ends_at', '<=', $now->utc()),
        ];
        $counts = array_map(fn (Builder $query): int => (clone $query)->count(), $queries);
        $last = max(1, (int) ceil($counts[$group] / 8));
        $page = min(max(1, $page), $last);
        $query = clone $queries[$group];
        $query->join('customers as c', fn ($join) => $join->on('c.id', '=', 'reservations.customer_id')->on('c.company_id', '=', 'reservations.company_id'))
            ->join('vehicles as v', fn ($join) => $join->on('v.id', '=', 'reservations.vehicle_id')->on('v.company_id', '=', 'reservations.company_id'));
        $query->orderByRaw($group === 'departures' ? 'COALESCE(reservations.assignment_starts_at, reservations.starts_at)' : 'reservations.ends_at')->orderBy('reservations.id');
        $rows = $query->skip(($page - 1) * 8)->take(8)->get(['reservations.*', 'c.first_name', 'c.last_name', 'c.phone', 'v.plate', 'v.make', 'v.model']);

        return [
            'counts' => $counts, 'group' => $group, 'page' => $page, 'last_page' => $last,
            'today' => $now->toDateString(),
            'rows' => $rows->map(function ($row) use ($group, $now): array {
                $moment = $group === 'departures' ? ($row->assignment_starts_at ?? $row->starts_at) : $row->ends_at;
                return [
                    'id' => $row->id, 'reference' => 'DEM-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                    'customer' => trim($row->first_name.' '.$row->last_name), 'phone' => $row->phone,
                    'vehicle' => trim($row->make.' '.$row->model), 'plate' => $row->plate,
                    'at' => $moment->setTimezone('Africa/Casablanca')->format('Y-m-d\TH:i'),
                    'late' => $moment <= $now,
                ];
            })->all(),
        ];
    }
}
