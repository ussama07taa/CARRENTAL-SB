<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RentalReturn
{
    private function simulation(array $pickup): bool
    {
        return (bool) ($pickup['demo_simulation'] ?? false)
            || (app()->environment('local') && DB::connection()->getDatabaseName() === 'bensebih_pickup_sandbox_20261019');
    }

    private function table(string $name): Builder
    {
        return DB::table($name)->where('company_id', Tenant::id());
    }

    public function unreadyVehicles(): array
    {
        if (! Schema::hasTable('rental_returns')) {
            return [];
        }
        $returns = $this->table('rental_returns')->whereIn('id', $this->table('rental_returns')->selectRaw('MAX(id)')->groupBy('vehicle_id'))->get();
        $inspections = $this->table('rental_return_inspections')->whereIn('return_id', $returns->pluck('id'))->orderByDesc('id')->get()->unique('return_id')->keyBy('return_id');
        $approvals = $this->table('rental_return_readiness')->whereIn('inspection_id', $inspections->pluck('id'))->pluck('inspection_id')->all();
        $ids = app(\App\Support\Vehicles\DamageTickets::class)->blockers();
        foreach ($returns as $return) {
            $inspection = $inspections->get($return->id);
            if (! $inspection || $inspection->condition === 'anomalie_bloquante' || ! in_array($inspection->id, $approvals, true)) {
                $ids[] = (int) $return->vehicle_id;
            }
        }

        return $ids;
    }

    public function canApprove(CompanyMembership $actor): bool
    {
        return config('rental_returns.readiness_approvers', 'pending') === 'staff'
            || (config('rental_returns.readiness_approvers', 'pending') === 'responsible' && $actor->is_primary_responsible);
    }

    private function selectedReturn(Reservation $record, ?int $id = null): ?object
    {
        return $id ? $this->table('rental_returns')->where('reservation_id', $record->id)->where('id', $id)->firstOrFail() : app(RentalVehicleSwap::class)->returned($record);
    }

    public function summary(Reservation $record, CompanyMembership $actor, ?int $returnId = null): array
    {
        $ready = Schema::hasTable('rental_returns');
        $return = $ready ? $this->selectedReturn($record, $returnId) : null;
        $pickup = $return ? $this->table('rental_pickups')->where('reservation_id', $record->id)->where('id', $return->pickup_id)->firstOrFail() : app(RentalVehicleSwap::class)->pickup($record);
        $snapshot = $pickup ? json_decode($pickup->snapshot, true) : null;
        $inspection = $return ? $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first() : null;
        $files = $inspection ? $this->table('rental_return_files')->where('inspection_id', $inspection->id)->get(['id', 'kind', 'mime', 'caption', 'size']) : [];
        $approved = $inspection && $this->table('rental_return_readiness')->where('inspection_id', $inspection->id)->exists();
        $vehicle = Vehicle::findOrFail($return?->vehicle_id ?? $record->vehicle_id);
        $garage = in_array($vehicle->id, app(RequestAvailability::class)->garageVehicles(), true);

        return ['ready' => $ready, 'status' => $record->status, 'can_receive' => $ready && $pickup && ! $return && $record->status === 'en_location', 'simulation' => $this->simulation($snapshot ?? []), 'server_now_local' => now(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'vehicle' => $vehicle->only(['id', 'make', 'model', 'plate', 'version', 'current_odometer', 'current_odometer_date']), 'departure' => $pickup ? ['picked_up_local' => CarbonImmutable::parse($pickup->picked_up_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'kilometers' => $snapshot['kilometers'], 'fuel_eighths' => $snapshot['fuel_eighths'], 'inspection_id' => $pickup->inspection_id] : null, 'return' => $return ? ['id' => $return->id, 'returned_local' => CarbonImmutable::parse($return->returned_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'reason' => $return->reason, 'snapshot' => json_decode($return->snapshot, true)] : null, 'inspection' => $inspection ? [...collect($inspection)->only(['id', 'previous_id', 'kilometers', 'fuel_eighths', 'condition', 'notes', 'signature_state', 'signature_reason', 'reason'])->all(), 'snapshot' => json_decode($inspection->snapshot, true), 'files' => $files] : null, 'vehicle_ready' => (bool) $approved && ! $garage && ! in_array((int) $vehicle->id, app(\App\Support\Vehicles\DamageTickets::class)->blockers(), true), 'in_garage' => $garage, 'can_approve_readiness' => $this->canApprove($actor), 'readiness_policy_pending' => config('rental_returns.readiness_approvers', 'pending') === 'pending', 'caution' => app(DeparturePreparation::class)->summary($record, $actor, false)['caution'], 'financial' => app(RentalPayment::class)->summary($record)];
    }

    public function receive(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless(Schema::hasTable('rental_returns'), 503, 'Le retour attend l’activation de la mise à jour.');
        $at = app(RequestPeriod::class)->instant($input['returned_local'], 'returned_local');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $at, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            Vehicle::whereKey($record->vehicle_id)->lockForUpdate()->firstOrFail();
            $old = $this->table('rental_returns')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à un autre retour.');

                return true;
            }
            if ($record->status !== 'en_location') {
                abort(response()->json(['code' => 'UNTRANSITIONABLE', 'message' => 'Ce dossier ne permet pas un nouveau retour.'], 422));
            }
            $pickup = app(RentalVehicleSwap::class)->pickup($record);
            abort_unless($at >= CarbonImmutable::parse($pickup->picked_up_at)->startOfMinute() && $at <= CarbonImmutable::now(), 422, 'Le retour doit suivre le départ et ne peut pas être dans le futur.');
            $snapshot = json_decode($pickup->snapshot, true);
            $returnId = DB::table('rental_returns')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $id, 'vehicle_id' => $record->vehicle_id, 'pickup_id' => $pickup->id, 'actor_membership_id' => $actor->id, 'returned_at' => $at, 'reason' => $input['reason'], 'snapshot' => json_encode(['departure' => $snapshot, 'expected_return' => $record->ends_at->toIso8601String(), 'rent_total_centimes' => $record->frozen_total_centimes, 'financial_at_return' => app(RentalPayment::class)->summary($record), 'simulation' => $this->simulation($snapshot ?? [])]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            DB::table('reservations')->where('company_id', Tenant::id())->where('id', $id)->update(['status' => 'retournee']);
            app(RentalContract::class)->event($id, $actor->id, 'rental_returned', ['from' => 'en_location', 'to' => 'retournee', 'return_id' => $returnId, 'returned_at' => $at->toIso8601String(), 'reason' => $input['reason']]);

            return false;
        });
    }

    public function inspect(int $id, CompanyMembership $actor, array $input, array $uploads, array $signatures): bool
    {
        $values = collect($input)->except(['photos', 'client_signature', 'agency_signature'])->all();
        $hash = hash('sha256', json_encode([$id, $values, array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $uploads), array_map(fn ($bytes) => hash('sha256', $bytes), $signatures)]));
        $written = [];
        try {
            return DB::transaction(function () use ($id, $actor, $input, $uploads, $signatures, $hash, &$written): bool {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
                $old = $this->table('rental_return_inspections')->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée.');

                    return true;
                }
                $return = $this->selectedReturn($record, isset($input['return_id']) ? (int) $input['return_id'] : null);
                abort_unless($return, 422, 'Enregistrez d’abord le retour de cette voiture.');
                $vehicle = Vehicle::whereKey($return->vehicle_id)->lockForUpdate()->firstOrFail();
                abort_unless($this->table('rental_returns')->where('vehicle_id', $vehicle->id)->max('id') === $return->id && ! Reservation::where('vehicle_id', $vehicle->id)->where('status', 'en_location')->exists(), 409, 'Une autre location ou un autre retour a commencé. Conservez ce contrôle dans son dossier d’origine.');
                $previous = $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first();
                abort_unless((int) ($previous?->id ?? 0) === (int) ($input['expected_inspection_id'] ?? 0) && $vehicle->version === (int) $input['vehicle_version'], 409, 'Le contrôle ou le fichier voiture a changé. Actualisez avant de reprendre.');
                $pickup = json_decode($return->snapshot, true)['departure'];
                abort_if((int) $input['kilometers'] < max($pickup['kilometers'], $vehicle->current_odometer ?? 0), 422, 'Le compteur ne peut pas diminuer. Le retour physique reste enregistré.');
                $retained = array_map('intval', $input['retained_file_ids'] ?? []);
                $existing = $this->table('rental_return_files')->where('return_id', $return->id)->where('inspection_id', $previous?->id ?? 0)->whereIn('kind', ['photo', 'video'])->whereIn('id', $retained)->get();
                abort_unless($existing->count() === count($retained) && count($uploads) + count($retained) <= 12, 422, 'Les fichiers conservés doivent appartenir au dernier contrôle ; maximum 12 médias.');
                abort_unless($existing->contains('kind', 'photo') || collect($uploads)->contains(fn ($file) => str_starts_with($file->getMimeType(), 'image/')), 422, 'Ajoutez au moins une photo. Le retour physique reste enregistré.');
                $inspectionId = DB::table('rental_return_inspections')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $id, 'return_id' => $return->id, 'actor_membership_id' => $actor->id, 'previous_id' => $previous?->id, ...collect($input)->only(['kilometers', 'fuel_eighths', 'condition', 'notes', 'signature_state', 'signature_reason', 'reason'])->all(), 'snapshot' => json_encode(['departure' => $pickup, 'distance_km' => (int) $input['kilometers'] - $pickup['kilometers'], 'fuel_difference_eighths' => (int) $input['fuel_eighths'] - $pickup['fuel_eighths'], 'automatic_charge_centimes' => 0]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $common = ['company_id' => Tenant::id(), 'reservation_id' => $id, 'return_id' => $return->id, 'inspection_id' => $inspectionId, 'created_at' => now()];
                foreach ($existing as $file) {
                    DB::table('rental_return_files')->insert([...$common, ...collect($file)->only(['kind', 'disk', 'path', 'mime', 'size', 'sha256', 'caption'])->all()]);
                }
                foreach ($uploads as $index => $file) {
                    $path = Tenant::id().'/'.$return->id.'/'.Str::uuid().'.'.$file->extension();
                    $written[] = $path;
                    abort_unless(Storage::disk('rental_returns')->putFileAs(dirname($path), $file, basename($path)), 503, 'Enregistrement du média impossible.');
                    DB::table('rental_return_files')->insert([...$common, 'kind' => str_starts_with($file->getMimeType(), 'image/') ? 'photo' : 'video', 'disk' => 'rental_returns', 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'caption' => $input['captions'][$index] ?? null]);
                }
                foreach ($signatures as $kind => $bytes) {
                    $path = Tenant::id().'/'.$return->id.'/'.Str::uuid().'.png';
                    $written[] = $path;
                    abort_unless(Storage::disk('rental_returns')->put($path, $bytes), 503, 'Enregistrement de la signature impossible.');
                    DB::table('rental_return_files')->insert([...$common, 'kind' => $kind, 'disk' => 'rental_returns', 'path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'caption' => null]);
                }
                DB::table('vehicle_odometer_readings')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id, 'kilometers' => (int) $input['kilometers'], 'recorded_on' => now(RequestPeriod::TIMEZONE)->toDateString(), 'kind' => 'reading', 'reason' => 'Contrôle retour #'.$inspectionId, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $before = $vehicle->only(['current_odometer', 'current_odometer_date', 'version']);
                $vehicle->current_odometer = (int) $input['kilometers'];
                $vehicle->current_odometer_date = now(RequestPeriod::TIMEZONE)->toDateString();
                $vehicle->version++;
                $vehicle->save();
                DB::table('vehicle_events')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id, 'action' => 'return_inspection_reading', 'before' => json_encode($before), 'after' => json_encode(['inspection_id' => $inspectionId, ...$vehicle->only(['current_odometer', 'current_odometer_date', 'version'])]), 'created_at' => now()]);
                app(RentalContract::class)->event($id, $actor->id, 'return_inspection_recorded', ['inspection_id' => $inspectionId, 'return_id' => $return->id, 'reason' => $input['reason']]);

                return false;
            });
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                Storage::disk('rental_returns')->delete($path);
            }throw $e;
        }
    }

    public function approve(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($this->canApprove($actor), 403, 'La validation de disponibilité attend la décision sur les personnes autorisées.');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = $this->table('rental_return_readiness')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409);

                return true;
            }
            $return = $this->selectedReturn($record, isset($input['return_id']) ? (int) $input['return_id'] : null);
            abort_unless($return, 422, 'Enregistrez le retour et son contrôle avant la disponibilité.');
            Vehicle::whereKey($return->vehicle_id)->lockForUpdate()->firstOrFail();
            $inspection = $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first();
            abort_unless($inspection && $inspection->id === (int) $input['inspection_id'] && $inspection->condition !== 'anomalie_bloquante', 422, 'Contrôlez la voiture et résolvez les anomalies bloquantes.');
            abort_if(in_array((int) $return->vehicle_id, app(\App\Support\Vehicles\DamageTickets::class)->blockers(), true), 409, 'Un dommage attend sa décision de disponibilité. Ouvrez le dossier de dommage.');
            abort_unless($this->table('rental_returns')->where('vehicle_id', $return->vehicle_id)->max('id') === $return->id && ! Reservation::where('vehicle_id', $return->vehicle_id)->where('status', 'en_location')->exists() && ! in_array($return->vehicle_id, app(RequestAvailability::class)->garageVehicles(), true), 409, 'Une autre sortie ou un passage au garage empêche cette validation.');
            DB::table('rental_return_readiness')->insert(['company_id' => Tenant::id(), 'reservation_id' => $id, 'return_id' => $return->id, 'inspection_id' => $inspection->id, 'actor_membership_id' => $actor->id, 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            app(RentalContract::class)->event($id, $actor->id, 'return_readiness_approved', ['inspection_id' => $inspection->id, 'return_id' => $return->id, 'reason' => $input['reason']]);

            return false;
        });
    }
}
