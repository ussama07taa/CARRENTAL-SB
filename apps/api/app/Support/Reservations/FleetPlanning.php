<?php

namespace App\Support\Reservations;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleMaintenance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FleetPlanning
{
    public function snapshot(array $input): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], RequestPeriod::TIMEZONE);
        $until = $from->addDays((int) $input['days']);
        $search = mb_strtolower(trim($input['q'] ?? ''));
        $vehicles = Vehicle::orderBy('make')->orderBy('model')->orderBy('plate')->get()
            ->filter(fn ($v): bool => $search === '' || str_contains(mb_strtolower($v->plate.' '.$v->make.' '.$v->model), $search));
        $hasReturns = Schema::hasTable('rental_returns');
        $returnRows = $hasReturns ? DB::table('rental_returns')->where('company_id', Tenant::id())->orderBy('id')->get()->keyBy('reservation_id') : collect();
        $returnPending = app(RentalReturn::class)->unreadyVehicles();
        $requests = Reservation::where('status', '<>', 'annulee')->whereIn('vehicle_id', $vehicles->modelKeys())
            ->where('starts_at', '<', $until)->where(fn ($q) => $q->where('ends_at', '>', $from)->orWhere('status', 'en_location')->orWhereIn('id', $returnRows->filter(fn ($row) => CarbonImmutable::parse($row->returned_at) > $from)->keys()))->orderBy('starts_at')->orderBy('id')->get();
        $blocked = app(RequestAvailability::class)->garageVehicles();
        $out = Reservation::where('status', 'en_location')->get()->keyBy('vehicle_id');
        $counts = ['vehicles' => $vehicles->count(), 'requests' => $requests->count(), 'garage' => $vehicles->whereIn('id', $blocked)->count()];
        $filter = $input['filter'] ?? 'all';
        if ($filter === 'garage') {
            $vehicles = $vehicles->whereIn('id', $blocked);
        } elseif ($filter === 'requests') {
            $vehicles = $vehicles->whereIn('id', $requests->pluck('vehicle_id'));
        }
        $total = $vehicles->count();
        $last = max(1, (int) ceil($total / 15));
        $page = min((int) ($input['page'] ?? 1), $last);
        $vehicles = $vehicles->values()->slice(($page - 1) * 15, 15);
        $ids = $vehicles->pluck('id');
        $pastLegs = $hasReturns ? DB::table('rental_pickups as p')->join('rental_returns as returned', function ($join): void {
            $join->on('returned.company_id', '=', 'p.company_id')->on('returned.pickup_id', '=', 'p.id');
        })->join('reservations as rental', function ($join): void {
            $join->on('rental.company_id', '=', 'p.company_id')->on('rental.id', '=', 'p.reservation_id');
        })->where('p.company_id', Tenant::id())->whereIn('p.vehicle_id', $ids)->when(Schema::hasTable('rental_vehicle_swaps'), fn ($q) => $q->whereRaw('p.swap_id IS DISTINCT FROM rental.active_swap_id'), fn ($q) => $q->whereColumn('p.vehicle_id', '<>', 'rental.vehicle_id'))->where('p.picked_up_at', '<', $until)->where('returned.returned_at', '>', $from)->get(['p.id', 'p.vehicle_id', 'p.reservation_id', 'p.picked_up_at', 'returned.returned_at', 'rental.customer_id']) : collect();
        $customers = Customer::whereIn('id', [...$requests->whereIn('vehicle_id', $ids)->pluck('customer_id')->all(), ...$pastLegs->pluck('customer_id')->all()])->get()->keyBy('id');
        $covers = VehiclePhoto::whereIn('vehicle_id', $ids)->where('slot', 'cover')->orderByDesc('version')->get()->unique('vehicle_id')->keyBy('vehicle_id');
        $maintenance = app(VehicleMaintenance::class);
        $entries = $maintenance->query()->whereIn('vehicle_id', $ids)->get();
        $returns = $maintenance->query('maintenance_returns')->whereIn('maintenance_id', $entries->pluck('id'))->get()->keyBy('maintenance_id');
        $followups = $maintenance->query('maintenance_followups')->whereIn('maintenance_id', $entries->pluck('id'))->orderBy('id')->get();
        $cancelled = $maintenance->query('maintenance_cancellations')->whereIn('maintenance_id', $entries->pluck('id'))->get();
        $events = [];
        foreach ($requests->whereIn('vehicle_id', $ids) as $r) {
            $events[$r->vehicle_id][] = ['key' => 'request-'.$r->id, 'kind' => $r->status === 'retournee' ? 'returned' : ($r->status === 'en_location' ? 'out' : ($r->status === 'confirmee' ? 'confirmed' : (($r->status === 'temporaire' && $r->hold_expires_at > now()) ? 'hold' : 'request'))), 'hold_expires_at' => $r->hold_expires_at?->toIso8601String(), 'id' => $r->id, 'title' => trim($customers[$r->customer_id]->first_name.' '.$customers[$r->customer_id]->last_name), 'reference' => 'DEM-'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT), 'starts_local' => ($r->assignment_starts_at ?? $r->starts_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => $r->status === 'retournee' && isset($returnRows[$r->id]) ? CarbonImmutable::parse($returnRows[$r->id]->returned_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i') : $r->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'overdue' => $r->status === 'en_location' && $r->ends_at <= now(), 'threatened' => $r->status === 'confirmee' && isset($out[$r->vehicle_id]) && $out[$r->vehicle_id]->ends_at <= now(), 'blocks_vehicle' => in_array($r->status, ['confirmee', 'en_location'], true) || ($r->status === 'temporaire' && $r->hold_expires_at > now())];
        }
        foreach ($pastLegs as $leg) {
            $customer = $customers->get($leg->customer_id);
            $events[$leg->vehicle_id][] = ['key' => 'past-leg-'.$leg->id, 'kind' => 'returned', 'id' => $leg->reservation_id, 'title' => trim($customer->first_name.' '.$customer->last_name).' · voiture remplacée', 'reference' => 'DEM-'.str_pad((string) $leg->reservation_id, 6, '0', STR_PAD_LEFT), 'starts_local' => CarbonImmutable::parse($leg->picked_up_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => CarbonImmutable::parse($leg->returned_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'overdue' => false, 'threatened' => false, 'blocks_vehicle' => false];
        }
        foreach ($entries as $entry) {
            $voids = $cancelled->where('maintenance_id', $entry->id);
            if ($voids->contains('target', 'file')) {
                continue;
            }
            $visits = [];
            if ($entry->in_garage) {
                $visits[] = ['key' => 'garage-'.$entry->id, 'start' => $entry->performed_on, 'end' => $returns->get($entry->id)?->returned_on];
            }
            foreach ($followups->where('maintenance_id', $entry->id)->where('action', 'start') as $visit) {
                if ($voids->contains('target', $visit->item_key)) {
                    continue;
                }
                $returned = $followups->where('maintenance_id', $entry->id)->where('action', 'return')->firstWhere('visit_id', $visit->id);
                $visits[] = ['key' => 'visit-'.$visit->id, 'start' => $visit->occurred_on, 'end' => $returned?->occurred_on];
            }
            foreach ($visits as $visit) {
                // Maintenance records have dates, not times: show the return day without inventing an hour.
                $end = $visit['end'] ? CarbonImmutable::parse($visit['end'], RequestPeriod::TIMEZONE)->addDay()->format('Y-m-d\T00:00') : null;
                $start = $visit['start'].'T00:00';
                if ($start >= $until->format('Y-m-d\TH:i') || ($end !== null && $end <= $from->format('Y-m-d\TH:i'))) {
                    continue;
                }
                $events[$entry->vehicle_id][] = ['key' => $visit['key'], 'kind' => 'garage', 'id' => $entry->id, 'title' => $entry->title, 'starts_local' => $start, 'ends_local' => $end, 'returned_on' => $visit['end'], 'blocks_vehicle' => $end === null];
            }
        }
        $rows = $vehicles->map(fn ($v): array => ['vehicle' => [...$v->only(['id', 'plate', 'make', 'model', 'year', 'current_odometer', 'current_odometer_date']), 'cover_id' => $covers->get($v->id)?->id], 'in_garage' => in_array($v->id, $blocked, true), 'return_pending' => in_array($v->id, $returnPending, true), 'out_now' => isset($out[$v->id]), 'overdue' => isset($out[$v->id]) && $out[$v->id]->ends_at <= now(), 'events' => collect($events[$v->id] ?? [])->sortBy('starts_local')->values()])->values();

        return ['data' => $rows, 'counts' => $counts, 'total' => $total, 'page' => $page, 'last_page' => $last, 'from' => $from->toDateString(), 'until' => $until->toDateString(), 'today' => now(RequestPeriod::TIMEZONE)->toDateString(), 'checked_at' => now()->toIso8601String()];
    }
}
