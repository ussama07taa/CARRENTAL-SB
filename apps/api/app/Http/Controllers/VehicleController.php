<?php

namespace App\Http\Controllers;

use App\Actions\Vehicles\SaveVehicle;
use App\Http\Requests\SaveVehicleRequest;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Reservations\VehiclePricing;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'make' => ['nullable', 'string', 'max:60'], 'fuel' => ['nullable', Rule::in(VehicleFields::FUELS)], 'transmission' => ['nullable', Rule::in(VehicleFields::TRANSMISSIONS)]]);
        $query = Vehicle::query();
        $search = trim($input['q'] ?? '');
        if ($search !== '') {
            $literal = fn (string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
            $query->where(function ($q) use ($search, $literal): void {
                $q->whereRaw("(make || ' ' || model || ' ' || coalesce(trim, '')) ILIKE ?", ['%'.$literal($search).'%']);
                $key = VehicleFields::identifier($search);
                if ($key !== '') {
                    $q->orWhere('plate_key', 'like', '%'.$literal($key).'%');
                }
                if (preg_match('/^VH-0*([1-9][0-9]{0,17})$/i', $search, $m)) {
                    $q->orWhere('id', $m[1]);
                }
            });
        }
        foreach (['make', 'fuel', 'transmission'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        $rows = $query->latest('id')->paginate(15, ['id', 'plate', 'make', 'model', 'year', 'fuel', 'transmission', 'current_odometer', 'current_odometer_date', 'updated_at']);
        $covers = VehiclePhoto::whereIn('vehicle_id', collect($rows->items())->pluck('id'))->where('slot', 'cover')->orderByDesc('version')->get()->unique('vehicle_id')->keyBy('vehicle_id');
        $pricingReady = app(VehiclePricing::class)->ready();
        $pricingVersion = $pricingReady ? DB::table('vehicle_pricing_versions')->where('company_id', Tenant::id())->latest('id')->value('id') : null;
        $rates = $pricingVersion ? DB::table('vehicle_pricing_rates')->where('company_id', Tenant::id())->where('version_id', $pricingVersion)->whereIn('vehicle_id', collect($rows->items())->pluck('id'))->whereNull('season_key')->pluck('daily_centimes', 'vehicle_id') : collect();
        $data = collect($rows->items())->map(fn ($v) => [...$v->toArray(), 'cover_id' => $covers->get($v->id)?->id, 'normal_daily_centimes' => $rates->has($v->id) ? (int) $rates->get($v->id) : null]);

        return response()->json(['data' => $data, 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            'fleet_total' => Vehicle::count(), 'makes' => Vehicle::select('make')->distinct()->orderBy('make')->pluck('make'), 'can_manage_pricing' => CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists(), 'pricing_ready' => $pricingReady]);
    }

    public function show(Request $request, int $vehicle): JsonResponse
    {
        $record = Vehicle::findOrFail($vehicle);
        $readings = DB::table('vehicle_odometer_readings as r')->join('company_memberships as m', function ($j): void {
            $j->on('r.company_id', '=', 'm.company_id')->on('r.actor_membership_id', '=', 'm.id');
        })
            ->join('users as u', 'u.id', '=', 'm.user_id')->where('r.company_id', Tenant::id())->where('r.vehicle_id', $vehicle)->latest('r.id')->limit(50)->get(['r.id', 'r.kilometers', 'r.recorded_on', 'r.kind', 'r.reason', 'u.name as actor']);

        return response()->json(['data' => $record, 'photos' => VehiclePhoto::where('vehicle_id', $vehicle)->orderByDesc('version')->get()->unique('slot')->values(),
            'readings' => $readings, 'can_correct_odometer' => CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists()]);
    }

    public function store(SaveVehicleRequest $request, SaveVehicle $save): JsonResponse
    {
        return response()->json(['data' => $save->handle($request->validated(), $request->user()->id)], 201);
    }

    public function update(SaveVehicleRequest $request, int $vehicle, SaveVehicle $save): JsonResponse
    {
        return response()->json(['data' => $save->handle($request->validated(), $request->user()->id, $vehicle)]);
    }
}
