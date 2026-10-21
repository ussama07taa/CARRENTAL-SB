<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleOdometerRequest;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleOdometerController extends Controller
{
    public function store(StoreVehicleOdometerRequest $request, int $vehicle): JsonResponse
    {
        $input = $request->validated();

        return DB::transaction(function () use ($request, $vehicle, $input): JsonResponse {
            $company = Tenant::id();
            Company::whereKey($company)->lockForUpdate()->firstOrFail();
            $record = Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$vehicle, (int) $input['kilometers'], $input['recorded_on'], $input['kind'], $input['reason'] ?? null]));
            $previous = DB::table('vehicle_odometer_readings')->where('company_id', $company)->where('request_key', $input['request_key'])->first();
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    return response()->json(['message' => 'Cette demande a déjà été utilisée.', 'code' => 'REQUEST_ALREADY_USED'], 409);
                }

                return response()->json(['data' => $record]);
            }
            if ($record->version !== (int) $input['version']) {
                return response()->json(['code' => 'VEHICLE_CHANGED', 'message' => 'Le fichier a changé. Fermez-le puis rouvrez-le avant de saisir un relevé.'], 409);
            }
            if ($record->current_odometer_date && $input['recorded_on'] < $record->current_odometer_date) {
                throw ValidationException::withMessages(['recorded_on' => 'La date doit être au moins celle du dernier relevé.']);
            }
            if ($input['kind'] === 'reading' && $record->current_odometer !== null && (int) $input['kilometers'] < $record->current_odometer) {
                throw ValidationException::withMessages(['kilometers' => 'Le kilométrage ne peut pas diminuer. Utilisez une correction motivée en cas d’erreur.']);
            }
            $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
            $before = $record->only(['current_odometer', 'current_odometer_date']);
            DB::table('vehicle_odometer_readings')->insert(['company_id' => $company, 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id,
                'kilometers' => (int) $input['kilometers'], 'recorded_on' => $input['recorded_on'], 'kind' => $input['kind'], 'reason' => $input['reason'] ?? null, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $record->current_odometer = (int) $input['kilometers'];
            $record->current_odometer_date = $input['recorded_on'];
            $record->version++;
            $record->save();
            DB::table('vehicle_events')->insert(['company_id' => $company, 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id,
                'action' => 'odometer_'.$input['kind'], 'before' => json_encode($before), 'after' => json_encode([...$record->only(['current_odometer', 'current_odometer_date']), 'reason' => $input['reason'] ?? null]), 'created_at' => now()]);

            return response()->json(['data' => $record], 201);
        });
    }
}
