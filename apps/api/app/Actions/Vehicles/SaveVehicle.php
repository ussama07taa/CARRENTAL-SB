<?php

namespace App\Actions\Vehicles;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Reservations\VehiclePricing;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleFields;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class SaveVehicle
{
    public function handle(array $input, int $userId, ?int $id = null): Vehicle
    {
        return DB::transaction(function () use ($input, $userId, $id): Vehicle {
            $company = Tenant::id();
            $actor = CompanyMembership::where('user_id', $userId)->firstOrFail();
            if (array_key_exists('initial_daily_centimes', $input)) {
                abort_unless($actor->is_primary_responsible, 403, 'Seul le responsable peut définir le prix de location.');
            }
            Company::whereKey($company)->lockForUpdate()->firstOrFail();
            $values = array_intersect_key($input, array_flip(VehicleFields::EDITABLE));
            $values['equipment'] ??= [];
            sort($values['equipment']);
            ksort($values);
            $hash = hash('sha256', json_encode([$values, $input['initial_odometer'] ?? null, $input['initial_odometer_date'] ?? null]));
            if (isset($input['initial_daily_centimes'])) {
                $hash = hash('sha256', json_encode([$hash, (int) $input['initial_daily_centimes']]));
            }
            if ($id === null && ($existing = Vehicle::where('creation_key', $input['creation_key'])->first())) {
                if (! hash_equals($existing->creation_hash, $hash)) {
                    $this->conflict('REQUEST_ALREADY_USED', 'Cette demande a déjà été utilisée. Rouvrez le fichier enregistré.');
                }

                return $existing;
            }
            $vehicle = $id ? Vehicle::whereKey($id)->lockForUpdate()->firstOrFail() : new Vehicle;
            if ($id && $vehicle->version !== (int) $input['version']) {
                $this->conflict('VEHICLE_CHANGED', 'Ce fichier a été modifié ailleurs. Fermez-le puis rouvrez-le avant de reprendre.');
            }
            $plateKey = VehicleFields::identifier($values['plate']);
            if (Vehicle::where('plate_key', $plateKey)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->conflict('PLATE_ALREADY_USED', 'Cette immatriculation existe déjà dans votre parc.');
            }
            if (! empty($values['vin']) && Vehicle::where('vin', $values['vin'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->conflict('VIN_ALREADY_USED', 'Ce numéro de châssis existe déjà dans votre parc.');
            }
            $before = $vehicle->exists ? $vehicle->only(VehicleFields::EDITABLE) : null;
            $vehicle->fill($values);
            $vehicle->plate_key = $plateKey;
            if ($vehicle->exists && ! $vehicle->isDirty()) {
                return $vehicle;
            }
            if (! $vehicle->exists) {
                $vehicle->creation_key = $input['creation_key'];
                $vehicle->creation_hash = $hash;
                $vehicle->version = 1;
                $vehicle->current_odometer = $input['initial_odometer'] ?? null;
                $vehicle->current_odometer_date = $input['initial_odometer_date'] ?? null;
            } else {
                $vehicle->version++;
            }
            $vehicle->save();
            if ($before === null && isset($input['initial_daily_centimes'])) {
                $pricing = app(VehiclePricing::class);
                $pricing->setNormal($vehicle->id, ['daily_centimes' => (int) $input['initial_daily_centimes'], 'minimum_days' => 0, 'expected_version' => $pricing->current()?->id, 'request_key' => $input['creation_key'], 'reason' => 'Prix défini à la création du véhicule'], $actor->id);
            }
            if ($before === null && $vehicle->current_odometer !== null) {
                DB::table('vehicle_odometer_readings')->insert(['company_id' => $company, 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id,
                    'kilometers' => $vehicle->current_odometer, 'recorded_on' => $vehicle->current_odometer_date, 'kind' => 'initial', 'request_key' => $input['creation_key'], 'request_hash' => $hash, 'created_at' => now()]);
            }
            DB::table('vehicle_events')->insert(['company_id' => $company, 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id,
                'action' => $before === null ? 'created' : 'updated', 'before' => $before === null ? null : json_encode($before),
                'after' => json_encode($vehicle->only([...VehicleFields::EDITABLE, 'current_odometer', 'current_odometer_date'])), 'created_at' => now()]);

            return $vehicle;
        });
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), 409)->header('Cache-Control', 'private, no-store'));
    }
}
