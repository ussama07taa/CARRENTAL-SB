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

class RentalVehicleSwap
{
    public function ready(): bool
    {
        return Schema::hasTable('rental_vehicle_swaps');
    }

    private function table(string $name): Builder
    {
        return DB::table($name)->where('company_id', Tenant::id());
    }

    public function pickup(Reservation $record): ?object
    {
        $query = $this->table('rental_pickups')->where('reservation_id', $record->id);
        if ($this->ready()) {
            $query->where('swap_id', $record->active_swap_id);
        }

        return $query->latest('id')->first();
    }

    public function returned(Reservation $record): ?object
    {
        $pickup = $this->pickup($record);

        return $pickup ? $this->table('rental_returns')->where('reservation_id', $record->id)->where('pickup_id', $pickup->id)->first() : null;
    }

    public function priceAddition(int $reservation, int $after = 0): int
    {
        return $this->ready() ? (int) $this->table('rental_vehicle_swaps')->where('reservation_id', $reservation)->where('id', '>', $after)->sum('difference_centimes') : 0;
    }

    public function summary(Reservation $record, CompanyMembership $actor): array
    {
        if (! $this->ready()) {
            return ['ready' => false];
        }
        $return = $this->returned($record);
        $inspection = $return ? $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first() : null;
        $swaps = $this->table('rental_vehicle_swaps')->where('reservation_id', $record->id)->orderBy('id')->get();
        $pickups = $this->table('rental_pickups')->where('reservation_id', $record->id)->orderBy('id')->get();
        $returns = $this->table('rental_returns')->where('reservation_id', $record->id)->orderBy('id')->get()->keyBy('pickup_id');
        $vehicles = Vehicle::whereIn('id', [...$pickups->pluck('vehicle_id')->all(), $record->vehicle_id])->get()->keyBy('id');
        $controls = $this->table('rental_return_inspections')->where('reservation_id', $record->id)->orderByDesc('id')->get()->unique('return_id')->keyBy('return_id');
        $photos = $this->table('rental_inspection_photos')->where('reservation_id', $record->id)->whereIn('inspection_id', $pickups->pluck('inspection_id'))->get(['id', 'inspection_id', 'mime', 'caption'])->groupBy('inspection_id');
        $contractFiles = $this->table('rental_contract_files')->where('reservation_id', $record->id)->whereIn('contract_id', $pickups->pluck('contract_id'))->where('kind', 'original')->get()->keyBy('contract_id');
        $legs = $pickups->map(function ($pickup) use ($returns, $vehicles, $controls, $photos, $contractFiles): array {
            $returned = $returns->get($pickup->id);
            $departure = json_decode($pickup->snapshot, true);
            $control = $returned ? $controls->get($returned->id) : null;

            return ['departure_photos' => $photos->get($pickup->inspection_id, collect())->all(), 'original_file_id' => $contractFiles->get($pickup->contract_id)?->id, 'signed_file_id' => $pickup->signed_file_id, 'pickup_id' => $pickup->id, 'vehicle' => $vehicles->get($pickup->vehicle_id)?->only(['id', 'make', 'model', 'plate']), 'picked_up_local' => CarbonImmutable::parse($pickup->picked_up_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'return_id' => $returned?->id, 'returned_local' => $returned ? CarbonImmutable::parse($returned->returned_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i') : null, 'departure_kilometers' => $departure['kilometers'], 'departure_fuel_eighths' => $departure['fuel_eighths'], 'inspection_id' => $pickup->inspection_id, 'contract_id' => $pickup->contract_id, 'return_control' => $control ? collect($control)->only(['id', 'kilometers', 'fuel_eighths', 'condition', 'signature_state'])->all() : null];
        });

        return ['ready' => true, 'can_manage' => (bool) $actor->is_primary_responsible, 'status' => $record->status, 'can_prepare' => $record->status === 'retournee' && $return && $inspection && $record->ends_at > now(), 'return_id' => $return?->id, 'inspection_id' => $inspection?->id, 'active_swap_id' => $record->active_swap_id, 'server_now_local' => now(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => $record->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'current_vehicle_id' => $record->vehicle_id, 'financial' => app(RentalPayment::class)->summary($record), 'legs' => $legs->all(), 'swaps' => $swaps->map(fn ($s) => ['id' => $s->id, 'assigned_local' => CarbonImmutable::parse($s->assigned_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'difference_centimes' => (int) $s->difference_centimes, 'reason' => $s->reason, 'snapshot' => json_decode($s->snapshot, true)])->all()];
    }

    public function prepare(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($actor->is_primary_responsible, 403, 'Le responsable approuve la voiture de remplacement et le prix avec le client.');
        abort_unless($this->ready(), 503, 'Le remplacement attend l’activation de la mise à jour.');
        $hash = hash('sha256', json_encode([$id, $input]));
        $at = app(RequestPeriod::class)->instant($input['assigned_local'], 'assigned_local');

        return DB::transaction(function () use ($id, $actor, $input, $hash, $at): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = $this->table('rental_vehicle_swaps')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à un autre remplacement.');

                return true;
            }
            if ($record->status !== 'retournee') {
                abort(response()->json(['code' => 'UNTRANSITIONABLE', 'message' => 'Enregistrez d’abord le retour physique et le contrôle de la voiture actuelle.'], 422));
            }
            $return = $this->returned($record);
            $inspection = $return ? $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first() : null;
            abort_unless($return && $inspection, 422, 'Le contrôle de retour de la première voiture est nécessaire.');
            abort_unless($return->id === (int) $input['return_id'] && $inspection->id === (int) $input['inspection_id'] && (int) ($record->active_swap_id ?? 0) === (int) ($input['expected_swap_id'] ?? 0), 409, 'Le dossier ou le contrôle a changé. Actualisez avant de remplacer.');
            abort_unless($at >= CarbonImmutable::parse($return->returned_at) && $at >= $record->starts_at && $at < $record->ends_at && $at <= CarbonImmutable::now(), 422, 'L’affectation doit suivre le retour, rester dans la période convenue et ne pas être future.');
            $vehicle = Vehicle::whereKey($input['vehicle_id'])->lockForUpdate()->firstOrFail();
            abort_if($vehicle->id === $record->vehicle_id, 422, 'Choisissez une autre voiture.');
            $fromVehicle = Vehicle::whereKey($record->vehicle_id)->lockForUpdate()->firstOrFail();
            app(TemporaryHold::class)->expireLocked();
            $candidate = clone $record;
            $candidate->status = 'confirmee';
            $candidate->vehicle_id = $vehicle->id;
            $candidate->assignment_starts_at = $at;
            if (app(TemporaryHold::class)->conflicts($candidate)
                || Reservation::where('vehicle_id', $vehicle->id)->where('status', 'en_location')->exists()
                || in_array($vehicle->id, app(RequestAvailability::class)->blockedVehicles(), true)
                || in_array($vehicle->id, app(RentalReturn::class)->unreadyVehicles(), true)) {
                abort(response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'Cette voiture n’est pas disponible et prête sur la période restante. Le retour et les paiements restent conservés.'], 409));
            }
            $financial = app(RentalPayment::class)->summary($record);
            abort_unless((int) $financial['total_centimes'] === (int) $input['expected_total_centimes'], 409, 'Le compte a changé. Revoyez le prix avec le client.');
            $total = (int) $financial['total_centimes'] + (int) $input['difference_centimes'];
            abort_unless($total >= 0 && $total <= 99999999999, 422, 'Le loyer total après remplacement doit rester entre 0 et 999 999 999,99 DH.');
            $swap = $this->table('rental_vehicle_swaps')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'from_vehicle_id' => $record->vehicle_id, 'to_vehicle_id' => $vehicle->id, 'return_id' => $return->id, 'inspection_id' => $inspection->id, 'assigned_at' => $at, 'difference_centimes' => $input['difference_centimes'], 'reason' => $input['reason'], 'snapshot' => json_encode(['previous_swap_id' => $record->active_swap_id, 'from_vehicle' => $fromVehicle->only(['id', 'make', 'model', 'plate']), 'to_vehicle' => $vehicle->only(['id', 'make', 'model', 'plate']), 'starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'initial_total_centimes' => (int) $record->frozen_total_centimes, 'before_total_centimes' => (int) $financial['total_centimes'], 'after_total_centimes' => $total, 'agreement_confirmed' => true]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $this->table('reservations')->where('id', $id)->update(['vehicle_id' => $vehicle->id, 'active_swap_id' => $swap, 'assignment_starts_at' => $at, 'status' => 'confirmee']);
            app(RentalContract::class)->event($id, $actor->id, 'vehicle_swap_prepared', ['from' => 'retournee', 'to' => 'confirmee', 'swap_id' => $swap, 'return_id' => $return->id, 'from_vehicle_id' => $fromVehicle->id, 'to_vehicle_id' => $vehicle->id, 'amount_centimes' => (int) $input['difference_centimes'], 'reason' => $input['reason']]);

            return false;
        });
    }
}
