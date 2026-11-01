<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Support\Reservations\RequestPeriod;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FineRentalLookupController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate(['vehicle_id' => ['required', 'integer', 'min:1'], 'occurred_local' => ['required', 'date_format:Y-m-d\TH:i']]);
        $vehicle = Vehicle::findOrFail($input['vehicle_id']);
        $at = app(RequestPeriod::class)->instant($input['occurred_local'], 'occurred_local');
        if ($at->isFuture()) {
            throw ValidationException::withMessages(['occurred_local' => 'La date de l’infraction ne peut pas être future.']);
        }
        $rows = DB::table('rental_pickups as p')->where('p.company_id', Tenant::id())->where('p.vehicle_id', $vehicle->id)
            ->join('reservations as r', fn ($j) => $j->on('r.company_id', '=', 'p.company_id')->on('r.id', '=', 'p.reservation_id'))
            ->join('customers as c', fn ($j) => $j->on('c.company_id', '=', 'r.company_id')->on('c.id', '=', 'r.customer_id'))
            ->leftJoin('rental_returns as ret', fn ($j) => $j->on('ret.company_id', '=', 'p.company_id')->on('ret.pickup_id', '=', 'p.id'))
            ->where('p.picked_up_at', '<=', $at)->where(fn ($q) => $q->whereNull('ret.returned_at')->orWhere('ret.returned_at', '>', $at))
            ->orderByDesc('p.picked_up_at')->orderBy('p.id')
            ->get(['p.id as pickup_id', 'p.reservation_id', 'p.picked_up_at', 'ret.returned_at', 'c.first_name', 'c.last_name', 'c.id as customer_id', 'c.phone']);

        return response()->json(['vehicle' => $vehicle->only(['id', 'make', 'model', 'plate']), 'occurred_local' => $input['occurred_local'], 'timezone' => RequestPeriod::TIMEZONE,
            'data' => $rows->map(fn ($r) => ['reservation_id' => $r->reservation_id, 'pickup_id' => $r->pickup_id, 'customer_id' => $r->customer_id, 'phone' => $r->phone, 'customer_name' => trim($r->first_name.' '.$r->last_name), 'picked_up_local' => CarbonImmutable::parse($r->picked_up_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'returned_local' => $r->returned_at ? CarbonImmutable::parse($r->returned_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i') : null]),
            'requires_review' => true, 'recording_enabled' => true]);
    }
}
