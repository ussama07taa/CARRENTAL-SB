<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TemporaryHold
{
    public function ready(): bool
    {
        return Schema::hasTable('reservation_hold_requests');
    }

    public function preparationMinutes(): int
    {
        return $this->ready() ? (int) (DB::table('company_settings')->where('company_id', Tenant::id())->value('preparation_minutes') ?? 120) : 120;
    }

    public function active(): Collection
    {
        return $this->ready() ? Reservation::where(fn ($q) => $q->whereIn('status', ['confirmee', 'en_location'])->orWhere(fn ($q) => $q->where('status', 'temporaire')->where('hold_expires_at', '>', now())))->orderBy('id')->get() : collect();
    }

    public function conflicts(Reservation $record, ?Collection $holds = null, ?int $minutes = null): array
    {
        $until = in_array($record->status, ['confirmee', 'en_location'], true) || ($record->status === 'temporaire' && $record->hold_expires_at > now()) ? $record->blocked_until : $record->ends_at->addMinutes($minutes ?? $this->preparationMinutes());

        return ($holds ?? $this->active())->filter(fn ($hold) => $hold->id !== $record->id && $hold->vehicle_id === $record->vehicle_id && ($hold->assignment_starts_at ?? $hold->starts_at) < $until && $hold->blocked_until > ($record->assignment_starts_at ?? $record->starts_at))->map(fn ($hold) => [
            'id' => $hold->id, 'reference' => 'DEM-'.str_pad((string) $hold->id, 6, '0', STR_PAD_LEFT), 'status' => $hold->status, 'expires_at' => $hold->status === 'temporaire' ? $hold->hold_expires_at->toIso8601String() : null,
            'starts_local' => $hold->starts_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => $hold->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
            'blocked_until_local' => $hold->blocked_until->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
        ])->values()->all();
    }

    public function expire(): int
    {
        if (! $this->ready() || ! Reservation::where('status', 'temporaire')->where('hold_expires_at', '<=', now())->exists()) {
            return 0;
        }

        return DB::transaction(function (): int {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();

            return $this->expireLocked();
        });
    }

    public function expireLocked(): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $expired = Reservation::where('status', 'temporaire')->where('hold_expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($expired as $row) {
            $actor = DB::table('reservation_hold_requests')->where('company_id', Tenant::id())->where('reservation_id', $row->id)->orderByDesc('id')->value('actor_membership_id');
            DB::table('reservations')->where('company_id', Tenant::id())->where('id', $row->id)->update(['status' => 'demande']);
            DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $row->id, 'actor_membership_id' => $actor, 'action' => 'hold_expired', 'data' => json_encode(['from' => 'temporaire', 'to' => 'demande', 'expires_at' => $row->hold_expires_at->toIso8601String(), 'automatic' => true, 'blocks_vehicle' => false]), 'created_at' => now()]);
        }

        return $expired->count();
    }

    public function activate(int $id, string $key, int $actor): array
    {
        if (! $this->ready()) {
            throw new HttpException(503, 'Le blocage temporaire nécessite l’activation de la mise à jour de la base.');
        }

        return DB::transaction(function () use ($id, $key, $actor): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $previous = DB::table('reservation_hold_requests')->where('company_id', Tenant::id())->where('request_key', $key)->first();
            $this->expireLocked();
            $record->refresh();
            if ($previous) {
                if ((int) $previous->reservation_id !== $id) {
                    return ['error' => 'REQUEST_ALREADY_USED', 'message' => 'Cette opération a déjà été utilisée pour un autre dossier.', 'status' => 409];
                }

                return ['record' => $record, 'replayed' => true];
            }
            if ($record->status !== 'demande') {
                return ['error' => 'UNTRANSITIONABLE', 'message' => 'Ce dossier possède déjà une réservation temporaire. Sa durée n’a pas été prolongée.', 'status' => 422];
            }
            if ($record->starts_at <= now()) {
                throw ValidationException::withMessages(['reservation' => 'Le départ souhaité est déjà passé. Une réservation temporaire nécessite un départ futur.']);
            }
            $conflicts = $this->conflicts($record);
            if ($conflicts || in_array($record->vehicle_id, app(RequestAvailability::class)->blockedVehicles(), true)) {
                return ['error' => 'UNIT_UNAVAILABLE', 'message' => $conflicts ? 'Cette voiture est déjà bloquée sur ces dates par un autre dossier.' : 'Cette voiture est indisponible : passage au garage ou location en retard à régulariser.', 'status' => 409, 'conflicts' => $conflicts];
            }
            DB::table('company_settings')->insertOrIgnore(['company_id' => Tenant::id(), 'preparation_minutes' => 120]);
            $minutes = $this->preparationMinutes();
            $expires = CarbonImmutable::now()->addHours(2);
            $blockedUntil = $record->ends_at->addMinutes($minutes);
            DB::table('reservations')->where('company_id', Tenant::id())->where('id', $id)->update(['status' => 'temporaire', 'hold_expires_at' => $expires, 'blocked_until' => $blockedUntil, 'preparation_minutes' => $minutes]);
            DB::table('reservation_hold_requests')->insert(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor, 'request_key' => $key, 'expires_at' => $expires, 'created_at' => now()]);
            DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor, 'action' => 'hold_activated', 'data' => json_encode(['from' => 'demande', 'to' => 'temporaire', 'expires_at' => $expires->toIso8601String(), 'starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'blocked_until' => $blockedUntil->toIso8601String(), 'preparation_minutes' => $minutes, 'agreement_confirmed' => true, 'blocks_vehicle' => true]), 'created_at' => now()]);

            return ['record' => $record->refresh(), 'replayed' => false];
        });
    }
}
