<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Reservations\RequestPeriod;
use App\Support\Reservations\VehiclePricing;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VehiclePricingController extends Controller
{
    public function vehicle(Request $request, int $vehicle, VehiclePricing $pricing): JsonResponse
    {
        return response()->json(['data' => $pricing->normal($vehicle), 'can_edit' => CompanyMembership::where('user_id', $request->user()->id)->firstOrFail()->is_primary_responsible]);
    }

    public function updateNormal(Request $request, int $vehicle, VehiclePricing $pricing): JsonResponse
    {
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($actor->is_primary_responsible, 403, 'Seul le responsable peut modifier les tarifs.');
        $input = $request->validate(['daily_centimes' => ['required', 'integer', 'min:1', 'max:999999999'], 'minimum_days' => ['required', 'integer', 'min:0', 'max:365'], 'expected_version' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:900'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un nombre entier valide.', 'min' => 'La valeur saisie est trop petite.', 'max' => 'La valeur dépasse la limite autorisée.']);
        $result = $pricing->setNormal($vehicle, $input, $actor->id);

        return response()->json(['data' => $pricing->normal($vehicle), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function index(Request $request, VehiclePricing $pricing): JsonResponse
    {
        $row = $pricing->current();
        $history = $pricing->ready() ? DB::table('vehicle_pricing_versions as p')->join('company_memberships as m', function ($j) {
            $j->on('m.id', '=', 'p.actor_membership_id')->on('m.company_id', '=', 'p.company_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('p.company_id', Tenant::id())->latest('p.id')->limit(20)->get(['p.id', 'p.reason', 'p.created_at', 'u.name as actor']) : [];

        return response()->json(['ready' => $pricing->ready(), 'version_id' => $row?->id, 'configuration' => $row ? json_decode($row->configuration, true) : ['vehicles' => [], 'seasons' => []], 'vehicles' => Vehicle::orderBy('make')->orderBy('plate')->get(['id', 'plate', 'make', 'model']), 'can_edit' => CompanyMembership::where('user_id', $request->user()->id)->firstOrFail()->is_primary_responsible, 'history' => $history]);
    }

    public function store(Request $request, VehiclePricing $pricing): JsonResponse
    {
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($actor->is_primary_responsible, 403, 'Seul le responsable peut modifier les tarifs.');
        $input = $request->validate(['request_key' => ['required', 'uuid'], 'expected_version' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'configuration' => ['required', 'array:vehicles,seasons'], 'configuration.vehicles' => ['present', 'array', 'max:1000'], 'configuration.vehicles.*' => ['array:vehicle_id,daily_centimes,minimum_days'], 'configuration.vehicles.*.vehicle_id' => ['required', 'integer', 'distinct'], 'configuration.vehicles.*.daily_centimes' => ['required', 'integer', 'min:1', 'max:999999999'], 'configuration.vehicles.*.minimum_days' => ['required', 'integer', 'min:0', 'max:365'], 'configuration.seasons' => ['present', 'array', 'max:200'], 'configuration.seasons.*' => ['array:key,name,kind,starts_on,ends_on,active,rates,calendar_id'], 'configuration.seasons.*.calendar_id' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'], 'configuration.seasons.*.key' => ['required', 'uuid', 'distinct'], 'configuration.seasons.*.name' => ['required', 'string', 'max:100'], 'configuration.seasons.*.kind' => ['required', Rule::in(['summer', 'school', 'eid', 'custom'])], 'configuration.seasons.*.starts_on' => ['required', 'date_format:Y-m-d'], 'configuration.seasons.*.ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:configuration.seasons.*.starts_on'], 'configuration.seasons.*.active' => ['required', 'boolean'], 'configuration.seasons.*.rates' => ['present', 'array', 'max:1000'], 'configuration.seasons.*.rates.*' => ['array:vehicle_id,daily_centimes,minimum_days'], 'configuration.seasons.*.rates.*.vehicle_id' => ['required', 'integer'], 'configuration.seasons.*.rates.*.daily_centimes' => ['nullable', 'integer', 'min:1', 'max:999999999'], 'configuration.seasons.*.rates.*.minimum_days' => ['required', 'integer', 'min:0', 'max:365'], 'company_id' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'after_or_equal' => 'La fin doit être après le début.', 'integer' => 'Saisissez un nombre entier valide.', 'min' => 'La valeur saisie est trop petite.', 'max' => 'La valeur dépasse la limite autorisée.']);
        foreach ($input['configuration']['seasons'] as $s) {
            abort_unless(count(array_unique(array_column($s['rates'], 'vehicle_id'))) === count($s['rates']), 422, 'Une voiture ne peut apparaître qu’une fois par période.');
        }

        $result = $pricing->save($input, $actor->id);

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function simulate(Request $request, VehiclePricing $pricing, RequestPeriod $period): JsonResponse
    {
        $input = $request->validate(['vehicle_id' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($input, $period, $request, $pricing) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json(['data' => $pricing->simulate((int) $input['vehicle_id'], $period->validate($request, true))]);
        });
    }
}
