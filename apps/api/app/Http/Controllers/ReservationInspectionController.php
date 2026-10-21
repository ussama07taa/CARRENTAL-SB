<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\DepartureInspection;
use App\Support\Reservations\RentalVehicleSwap;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationInspectionController extends Controller
{
    public function show(int $reservation): JsonResponse
    {
        return DB::transaction(function () use ($reservation): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json(['data' => app(DepartureInspection::class)->summary(Reservation::findOrFail($reservation))]);
        });
    }

    public function store(Request $request, int $reservation): JsonResponse
    {
        abort_unless(Schema::hasTable('rental_departure_inspections'), 503, 'Le contrôle attend l’activation de la mise à jour.');
        $input = $request->validate(['expected_inspection_id' => ['present', 'nullable', 'integer', 'min:1'], 'vehicle_version' => ['required', 'integer', 'min:1'], 'inspected_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'kilometers' => ['required', 'integer', 'between:0,9999999'], 'fuel_eighths' => ['required', 'integer', 'between:0,8'], 'condition' => ['required', Rule::in(['bon', 'dommages_existants', 'anomalie_bloquante'])], 'notes' => ['nullable', 'required_unless:condition,bon', 'string', 'min:3', 'max:4000'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'checked' => ['required', 'accepted'], 'photos' => ['sometimes', 'array', 'max:12'], 'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime', 'max:20480'], 'captions' => ['sometimes', 'array', 'max:12'], 'captions.*' => ['nullable', 'string', 'max:120'], 'retained_photo_ids' => ['sometimes', 'array', 'max:12'], 'retained_photo_ids.*' => ['integer', 'min:1', 'distinct'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'accepted' => 'Confirmez le contrôle réel de ces éléments.', 'required_unless' => 'Décrivez les marques ou anomalies constatées.', 'integer' => 'Saisissez un nombre entier.', 'between' => 'La valeur est hors limites.', 'date_format' => 'Saisissez une date valide.', 'before_or_equal' => 'Le contrôle ne peut pas être daté dans le futur.', 'photos.*.max' => 'Chaque vidéo doit faire au maximum 20 Mo.', 'photos.*.image' => 'Choisissez une photo JPG, PNG ou WebP.']);
        $files = $request->file('photos', []);
        $retained = array_map('intval', $input['retained_photo_ids'] ?? []);
        abort_if(array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 60 * 1024 * 1024, 422, 'Le total des nouveaux fichiers ne doit pas dépasser 60 Mo.');
        foreach ($files as $index => $file) {
            if (str_starts_with($file->getMimeType(), 'image/')) {
                validator(['photo' => $file], ['photo' => ['image', 'max:5120', 'dimensions:max_width=6000,max_height=6000']], ['photo.max' => 'Chaque photo doit faire au maximum 5 Mo.'])->validate();
            }
        }
        abort_if(count($files) + count($retained) < 1 || count($files) + count($retained) > 12, 422, 'Ajoutez entre 1 et 12 fichiers du contrôle, dont au moins une photo.');
        $values = ['expected_inspection_id' => isset($input['expected_inspection_id']) ? (int) $input['expected_inspection_id'] : null, 'vehicle_version' => (int) $input['vehicle_version'], 'inspected_on' => $input['inspected_on'], 'kilometers' => (int) $input['kilometers'], 'fuel_eighths' => (int) $input['fuel_eighths'], 'condition' => $input['condition'], 'notes' => $input['notes'] ?? null, 'reason' => $input['reason'], 'retained_photo_ids' => $retained];
        $hash = hash('sha256', json_encode([$reservation, $values, array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $files), $input['captions'] ?? []]));
        $written = [];
        try {
            $replayed = DB::transaction(function () use ($request, $reservation, $input, $values, $files, $retained, $hash, &$written): bool {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $record = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
                $old = DB::table('rental_departure_inspections')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée avec d’autres données.');

                    return true;
                }
                abort_unless($record->status === 'confirmee', 422, 'Confirmez la réservation avant de relever le contrôle de départ.');
                $vehicle = Vehicle::whereKey($record->vehicle_id)->lockForUpdate()->firstOrFail();
                $previous = DB::table('rental_departure_inspections')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('vehicle_id', $record->vehicle_id)->when(app(RentalVehicleSwap::class)->ready(), fn ($q) => $q->where('swap_id', $record->active_swap_id))->latest('id')->first();
                abort_unless((int) ($previous?->id ?? 0) === (int) ($values['expected_inspection_id'] ?? 0), 409, 'Un autre contrôle a été enregistré. Actualisez avant de reprendre.');
                abort_unless($vehicle->version === $values['vehicle_version'], 409, 'Le fichier voiture a changé. Actualisez le contrôle avant de reprendre.');
                abort_if($vehicle->current_odometer !== null && $values['kilometers'] < $vehicle->current_odometer, 422, 'Le compteur ne peut pas diminuer. Corrigez le relevé source si une erreur a été constatée.');
                abort_if($vehicle->current_odometer_date && $values['inspected_on'] < substr((string) $vehicle->current_odometer_date, 0, 10), 422, 'La date du contrôle doit être au moins celle du dernier relevé.');
                $existing = DB::table('rental_inspection_photos')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('inspection_id', $previous?->id ?? 0)->whereIn('id', $retained)->orderBy('id')->get();
                abort_unless($existing->count() === count($retained), 422, 'Une photo conservée n’appartient pas à la dernière version de ce contrôle.');
                abort_unless($existing->contains(fn ($media) => str_starts_with($media->mime, 'image/')) || collect($files)->contains(fn ($file) => str_starts_with($file->getMimeType(), 'image/')), 422, 'Ajoutez au moins une photo du contrôle. Une vidéo seule ne suffit pas.');
                $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
                $id = DB::table('rental_departure_inspections')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'vehicle_id' => $vehicle->id, ...(app(RentalVehicleSwap::class)->ready() ? ['swap_id' => $record->active_swap_id] : []), 'actor_membership_id' => $actor->id, 'previous_id' => $previous?->id, ...collect($values)->only(['inspected_on', 'kilometers', 'fuel_eighths', 'condition', 'notes', 'reason'])->all(), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                foreach ($existing as $photo) {
                    DB::table('rental_inspection_photos')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'inspection_id' => $id, ...collect($photo)->only(['disk', 'path', 'mime', 'size', 'sha256', 'caption'])->all(), 'created_at' => now()]);
                }
                foreach ($files as $index => $file) {
                    $path = Tenant::id().'/'.$reservation.'/'.Str::uuid().'.'.$file->extension();
                    $written[] = $path;
                    if (! Storage::disk('inspection_photos')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Photo storage failed.');
                    }
                    DB::table('rental_inspection_photos')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'inspection_id' => $id, 'disk' => 'inspection_photos', 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'caption' => $input['captions'][$index] ?? null, 'created_at' => now()]);
                }
                $before = $vehicle->only(['current_odometer', 'current_odometer_date']);
                DB::table('vehicle_odometer_readings')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id, 'kilometers' => $values['kilometers'], 'recorded_on' => $values['inspected_on'], 'kind' => 'reading', 'reason' => 'Inspection départ #'.$id, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $vehicle->current_odometer = $values['kilometers'];
                $vehicle->current_odometer_date = $values['inspected_on'];
                $vehicle->version++;
                $vehicle->save();
                DB::table('vehicle_events')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id, 'action' => 'departure_inspection_reading', 'before' => json_encode($before), 'after' => json_encode(['inspection_id' => $id, ...$vehicle->only(['current_odometer', 'current_odometer_date'])]), 'created_at' => now()]);
                DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'actor_membership_id' => $actor->id, 'action' => 'departure_inspection_recorded', 'data' => json_encode(['inspection_id' => $id, 'previous_id' => $previous?->id, 'reason' => $values['reason']]), 'created_at' => now()]);

                return false;
            });
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                Storage::disk('inspection_photos')->delete($path);
            }
            throw $e;
        }

        return response()->json(['data' => app(DepartureInspection::class)->summary(Reservation::findOrFail($reservation)), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function content(int $reservation, int $photo): StreamedResponse
    {
        Reservation::findOrFail($reservation);
        abort_unless(Schema::hasTable('rental_inspection_photos'), 404);
        $record = DB::table('rental_inspection_photos')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('id', $photo)->first();
        abort_unless($record, 404);
        abort_unless(Storage::disk($record->disk)->exists($record->path), 404, 'Photo indisponible.');

        return Storage::disk($record->disk)->response($record->path, 'inspection-photo.'.match ($record->mime) {
            'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', default => 'jpg'
        }, ['Content-Type' => $record->mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store, max-age=0']);
    }
}
