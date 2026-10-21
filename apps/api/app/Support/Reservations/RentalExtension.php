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

class RentalExtension
{
    public function ready(): bool
    {
        return Schema::hasTable('rental_extension_activations');
    }

    public function table(string $name): Builder
    {
        return DB::table($name)->where('company_id', Tenant::id());
    }

    public function agreement(int $reservation, int $agreement): object
    {
        return $this->table('rental_extension_agreements')->where('reservation_id', $reservation)->where('id', $agreement)->firstOrFail();
    }

    public function context(object $agreement): array
    {
        return ['id' => $agreement->id, 'old_ends_at' => CarbonImmutable::parse($agreement->old_ends_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d H:i'), 'new_ends_at' => CarbonImmutable::parse($agreement->new_ends_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d H:i'), 'amount_centimes' => (int) $agreement->amount_centimes, 'base_total_centimes' => (int) $agreement->base_total_centimes, 'reason' => $agreement->reason];
    }

    public function activeAgreement(Reservation $record): ?object
    {
        if (! $this->ready() || ! $record->active_extension_id) {
            return null;
        }
        $activation = $this->table('rental_extension_activations')->where('reservation_id', $record->id)->where('id', $record->active_extension_id)->firstOrFail();

        return $this->agreement($record->id, $activation->agreement_id);
    }

    public function priceAddition(int $id, int $after = 0): int
    {
        if (! $this->ready()) {
            return 0;
        }

        $ids = $this->table('rental_extension_activations')->where('reservation_id', $id)->where('id', '>', $after)->pluck('agreement_id');

        return (int) $this->table('rental_extension_agreements')->where('reservation_id', $id)->whereIn('id', $ids)->sum('amount_centimes');
    }

    public function halfDayAddition(int $id): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $ids = $this->table('rental_extension_activations')->where('reservation_id', $id)->pluck('agreement_id');

        return (int) $this->table('rental_extension_agreements')->where('reservation_id', $id)->whereIn('id', $ids)->get()->sum(fn ($a) => collect(data_get(json_decode($a->snapshot, true), 'pricing.lines', []))->sum('half_day_units'));
    }

    public function availability(Reservation $record, CarbonImmutable $end): array
    {
        $candidate = clone $record;
        $candidate->ends_at = $end;
        $candidate->blocked_until = $end->addMinutes($record->preparation_minutes);
        $conflicts = app(TemporaryHold::class)->conflicts($candidate);
        $garage = in_array($record->vehicle_id, app(RequestAvailability::class)->garageVehicles(), true);

        return ['available' => ! $conflicts && ! $garage, 'conflicts' => $conflicts, 'in_garage' => $garage];
    }

    private function requireOpen(Reservation $record): void
    {
        if (! in_array($record->status, ['confirmee', 'en_location'], true)) {
            abort(response()->json(['code' => 'UNTRANSITIONABLE', 'message' => 'La prolongation concerne une réservation confirmée ou une voiture encore en location. Le retour reste distinct.'], 422));
        }
    }

    public function quote(Reservation $record, string $newEnd): array
    {
        $this->requireOpen($record);
        $end = app(RequestPeriod::class)->instant($newEnd, 'ends_local');
        abort_unless($end > $record->ends_at && $end > now(), 422, 'Le nouveau retour doit suivre le retour convenu et être futur.');
        $period = ['starts_local' => $record->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => $newEnd, 'timezone' => RequestPeriod::TIMEZONE, 'duration_minutes' => (int) $record->ends_at->diffInMinutes($end)];
        $pricing = app(VehiclePricing::class)->simulate($record->vehicle_id, $period, false);
        $financial = app(RentalPayment::class)->summary($record);
        $fingerprint = hash('sha256', json_encode([$record->id, $record->vehicle_id, $record->active_swap_id, $record->active_extension_id, $record->ends_at->toIso8601String(), $financial['total_centimes'], $pricing]));

        return [...$this->availability($record, $end), 'fingerprint' => $fingerprint, 'pricing' => $pricing, 'base_total_centimes' => $financial['total_centimes'], 'old_ends_local' => $period['starts_local'], 'new_ends_local' => $newEnd];
    }

    public function propose(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($actor->is_primary_responsible, 403, 'Le responsable approuve l’accord et le prix de la prolongation.');
        abort_unless($this->ready(), 503, 'La prolongation attend l’activation de la mise à jour.');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            Vehicle::whereKey($record->vehicle_id)->lockForUpdate()->firstOrFail();
            $old = $this->table('rental_extension_agreements')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à un autre accord.');

                return true;
            }
            app(TemporaryHold::class)->expireLocked();
            $quote = $this->quote($record, $input['ends_local']);
            abort_unless(hash_equals($quote['fingerprint'], $input['fingerprint']) && (int) $this->table('rental_extension_agreements')->where('reservation_id', $id)->max('id') === (int) ($input['expected_latest_id'] ?? 0), 409, 'Le dossier, la grille ou l’accord a changé. Revoyez la simulation avec le client.');
            abort_unless($quote['available'], response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'La voiture est indisponible sur la période ajoutée. L’accord initial reste conservé.'], 409));
            abort_unless((int) $input['amount_centimes'] === $quote['pricing']['total_centimes'] || mb_strlen(trim($input['exception_reason'] ?? '')) >= 3, 422, 'Expliquez l’exception de prix approuvée par le responsable.');
            abort_unless($quote['base_total_centimes'] + (int) $input['amount_centimes'] <= 99999999999, 422, 'Le montant dépasse la limite du dossier.');
            $end = app(RequestPeriod::class)->instant($input['ends_local'], 'ends_local');
            $agreement = $this->table('rental_extension_agreements')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'vehicle_id' => $record->vehicle_id, 'swap_id' => $record->active_swap_id, 'previous_activation_id' => $record->active_extension_id, 'pricing_version_id' => $quote['pricing']['version_id'], 'old_ends_at' => $record->ends_at, 'new_ends_at' => $end, 'new_blocked_until' => $end->addMinutes($record->preparation_minutes), 'base_total_centimes' => $quote['base_total_centimes'], 'amount_centimes' => $input['amount_centimes'], 'exception_reason' => $input['exception_reason'] ?? null, 'reason' => $input['reason'], 'snapshot' => json_encode(['pricing' => $quote['pricing'], 'initial_total_centimes' => (int) $record->frozen_total_centimes, 'initial_confirmation' => $this->table('reservation_confirmations')->where('reservation_id', $id)->value('snapshot'), 'preparation_minutes' => $record->preparation_minutes, 'agreement_confirmed' => true]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            app(RentalContract::class)->event($id, $actor->id, 'extension_agreed', ['agreement_id' => $agreement, 'old_ends_at' => $record->ends_at->toIso8601String(), 'new_ends_at' => $end->toIso8601String(), 'amount_centimes' => (int) $input['amount_centimes'], 'exception_reason' => $input['exception_reason'] ?? null, 'reason' => $input['reason'], 'activated' => false]);

            return false;
        });
    }

    public function assertApplicable(Reservation $record, object $agreement): void
    {
        $this->requireOpen($record);
        $financial = app(RentalPayment::class)->summary($record);
        abort_unless($agreement->vehicle_id === $record->vehicle_id && (int) ($agreement->swap_id ?? 0) === (int) ($record->active_swap_id ?? 0) && (int) ($agreement->previous_activation_id ?? 0) === (int) ($record->active_extension_id ?? 0) && CarbonImmutable::parse($agreement->old_ends_at)->equalTo($record->ends_at) && (int) $agreement->base_total_centimes === (int) $financial['total_centimes'] && $agreement->id === $this->table('rental_extension_agreements')->where('reservation_id', $record->id)->max('id'), 409, 'Cet accord a été remplacé ou le dossier a changé. Préparez un nouvel accord ; les paiements restent conservés.');
    }

    public function funds(Reservation $record, object $agreement): array
    {
        $financial = app(RentalPayment::class)->summary($record);
        $reserved = app(RentalSettlement::class)->effects($record->id, 'rent')['refund_reserved'];
        $credit = max(0, $financial['surplus_centimes'] - $reserved);

        return ['verified_available_centimes' => $credit, 'pending_centimes' => $financial['pending_centimes'], 'remaining_centimes' => max(0, (int) $agreement->amount_centimes - $credit), 'current_remaining_centimes' => app(RentalSettlement::class)->briefs(collect([$record]))[$record->id]['remaining_centimes'] ?? $financial['remaining_centimes']];
    }

    public function contractSnapshot(Reservation $record, object $agreement): array
    {
        $snapshot = app(RentalContract::class)->snapshot($record);
        $snapshot['reservation']['ends_at'] = CarbonImmutable::parse($agreement->new_ends_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d H:i');
        $snapshot['reservation']['total_centimes'] = (int) $agreement->base_total_centimes + (int) $agreement->amount_centimes;

        return ['extension' => $this->context($agreement), ...collect($snapshot)->except('extension')->all()];
    }

    public function contractSummary(Reservation $record, int $agreementId): array
    {
        $agreement = $this->agreement($record->id, $agreementId);
        $this->assertApplicable($record, $agreement);
        $contracts = app(RentalContract::class);
        $data = $contracts->summary($record);
        $snapshot = $this->contractSnapshot($record, $agreement);
        $versions = $this->table('rental_contract_versions')->where('reservation_id', $record->id)->where('extension_id', $agreementId)->orderByDesc('version')->get();
        $files = $this->table('rental_contract_files')->where('reservation_id', $record->id)->whereIn('contract_id', $versions->pluck('id'))->orderBy('id')->get();
        $checks = $this->table('rental_contract_verifications')->where('reservation_id', $record->id)->pluck('file_id')->all();
        $latest = $versions->first();
        $signed = $latest ? $files->where('contract_id', $latest->id)->where('kind', 'signed')->last() : null;
        $fingerprint = $contracts->fingerprint($snapshot);
        $stale = $latest && ! hash_equals($latest->fingerprint, $fingerprint);
        $verified = $signed && in_array($signed->id, $checks, true) && ! $stale;
        $presented = $versions->map(function ($v) use ($files, $checks): array {
            $own = $files->where('contract_id', $v->id);
            $attempt = $this->table('rental_contract_render_events')->where('contract_id', $v->id)->latest('id')->value('action');

            return ['id' => $v->id, 'extension_id' => $v->extension_id, 'layout' => data_get(json_decode($v->snapshot, true), 'presentation.layout'), 'version' => $v->version, 'reference' => 'CTR-'.str_pad((string) $v->reservation_id, 6, '0', STR_PAD_LEFT).'-V'.$v->version, 'status' => $own->contains('kind', 'original') ? 'ready' : ($attempt === 'failed' ? 'failed' : 'pending'), 'created_at' => $v->created_at, 'reason' => $v->reason, 'files' => $own->map(fn ($f) => ['id' => $f->id, 'kind' => $f->kind, 'mime' => $f->mime, 'sha256' => $f->sha256, 'verified' => in_array($f->id, $checks, true)])->values()->all()];
        })->all();

        return [...$data, 'extension_id' => $agreementId, 'preview' => $snapshot, 'fingerprint' => $fingerprint, 'latest_id' => $latest?->id, 'versions' => $presented, 'stale' => (bool) $stale, 'signed_verified' => (bool) $verified, 'valid_for_pickup' => false];
    }

    public function activate(int $id, int $agreementId, CompanyMembership $actor, array $input): bool
    {
        abort_unless($actor->is_primary_responsible, 403, 'Le responsable active le nouvel accord après contrôle.');
        abort_unless($this->ready(), 503);
        $hash = hash('sha256', json_encode([$id, $agreementId, $input]));

        return DB::transaction(function () use ($id, $agreementId, $actor, $input, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            Vehicle::whereKey($record->vehicle_id)->lockForUpdate()->firstOrFail();
            $old = $this->table('rental_extension_activations')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à une autre activation.');

                return true;
            }
            $agreement = $this->agreement($id, $agreementId);
            $this->assertApplicable($record, $agreement);
            $funds = $this->funds($record, $agreement);
            abort_unless($funds['remaining_centimes'] === 0 && $funds['current_remaining_centimes'] === 0, 422, 'Recevez et vérifiez toute la période ajoutée et les montants déjà convenus. Les virements en attente ne comptent pas.');
            $end = CarbonImmutable::parse($agreement->new_ends_at);
            abort_unless($end > now(), 422, 'Le nouveau retour doit rester futur.');
            app(TemporaryHold::class)->expireLocked();
            abort_unless($this->availability($record, $end)['available'], response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'La disponibilité a changé. Le retour initial reste applicable et les fonds reçus restent conservés pour traitement.'], 409));
            $contracts = app(RentalContract::class);
            $version = $this->table('rental_contract_versions')->where('reservation_id', $id)->where('extension_id', $agreementId)->latest('version')->first();
            abort_unless($version && $version->id === (int) $input['contract_id'], 409, 'Ouvrez la dernière version de l’avenant.');
            $contracts->assertCurrent($record, $version->id);
            $files = $this->table('rental_contract_files')->where('reservation_id', $id)->where('contract_id', $version->id)->orderBy('id')->get();
            $original = $files->firstWhere('kind', 'original');
            $signed = $files->where('kind', 'signed')->last();
            abort_unless($original && $signed && $signed->id === (int) $input['signed_file_id'] && $this->table('rental_contract_verifications')->where('reservation_id', $id)->where('contract_id', $version->id)->where('file_id', $signed->id)->exists(), 422, 'Ajoutez et vérifiez la copie signée de cet avenant avant activation.');
            foreach ([$original, $signed] as $file) {
                abort_unless(Storage::disk('rental_contracts')->exists($file->path) && hash_equals($file->sha256, hash('sha256', Storage::disk('rental_contracts')->get($file->path))), 422, 'Un fichier de l’avenant est indisponible ou altéré.');
            }
            $activation = $this->table('rental_extension_activations')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $id, 'agreement_id' => $agreementId, 'actor_membership_id' => $actor->id, 'contract_id' => $version->id, 'signed_file_id' => $signed->id, 'reason' => $input['reason'], 'snapshot' => json_encode(['funds' => $funds, 'financial_before' => app(RentalPayment::class)->summary($record), 'contract_sha256' => $original->sha256, 'signed_sha256' => $signed->sha256, 'previous_activation_id' => $record->active_extension_id]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $this->table('reservations')->where('id', $id)->update(['ends_at' => $end, 'blocked_until' => $agreement->new_blocked_until, 'active_extension_id' => $activation]);
            $contracts->event($id, $actor->id, 'extension_activated', ['activation_id' => $activation, 'agreement_id' => $agreementId, 'old_ends_at' => $record->ends_at->toIso8601String(), 'new_ends_at' => $end->toIso8601String(), 'amount_centimes' => (int) $agreement->amount_centimes, 'contract_id' => $version->id, 'reason' => $input['reason']]);

            return false;
        });
    }

    public function summary(Reservation $record, CompanyMembership $actor): array
    {
        if (! $this->ready()) {
            return ['ready' => false];
        }
        $agreements = $this->table('rental_extension_agreements')->where('reservation_id', $record->id)->orderByDesc('id')->get();
        $activations = $this->table('rental_extension_activations')->where('reservation_id', $record->id)->get()->keyBy('agreement_id');
        $versions = $this->table('rental_contract_versions')->where('reservation_id', $record->id)->whereNotNull('extension_id')->orderByDesc('version')->get()->unique('extension_id')->keyBy('extension_id');
        $files = $this->table('rental_contract_files')->where('reservation_id', $record->id)->orderBy('id')->get();
        $verified = $this->table('rental_contract_verifications')->where('reservation_id', $record->id)->pluck('file_id')->all();
        $history = $agreements->map(function ($a) use ($record, $agreements, $activations, $versions, $files, $verified): array {
            $active = $activations->has($a->id);
            $current = ! $active && in_array($record->status, ['confirmee', 'en_location'], true) && $a->id === $agreements->first()->id && $a->vehicle_id === $record->vehicle_id && (int) ($a->swap_id ?? 0) === (int) ($record->active_swap_id ?? 0) && (int) ($a->previous_activation_id ?? 0) === (int) ($record->active_extension_id ?? 0) && (int) $a->base_total_centimes === app(RentalPayment::class)->summary($record)['total_centimes'] && CarbonImmutable::parse($a->old_ends_at)->equalTo($record->ends_at);
            $v = $versions->get($a->id);
            $own = $v ? $files->where('contract_id', $v->id) : collect();
            $signed = $own->where('kind', 'signed')->last();
            $funds = $current ? $this->funds($record, $a) : null;
            $availability = $current ? $this->availability($record, CarbonImmutable::parse($a->new_ends_at)) : null;

            return ['id' => $a->id, 'old_ends_local' => CarbonImmutable::parse($a->old_ends_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'new_ends_local' => CarbonImmutable::parse($a->new_ends_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'amount_centimes' => (int) $a->amount_centimes, 'reason' => $a->reason, 'exception_reason' => $a->exception_reason, 'state' => $active ? 'active' : ($current ? 'pending' : 'superseded'), 'funds' => $funds, 'availability' => $availability, 'contract' => $v ? ['id' => $v->id, 'version' => $v->version, 'reference' => 'CTR-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT).'-V'.$v->version, 'original' => $own->firstWhere('kind', 'original')?->id, 'signed' => $signed?->id, 'verified' => $signed && in_array($signed->id, $verified, true), 'stale' => ! hash_equals($v->fingerprint, app(RentalContract::class)->fingerprint($active ? app(RentalContract::class)->snapshot($record) : $this->contractSnapshot($record, $a)))] : null];
        })->all();

        return ['ready' => true, 'can_manage' => (bool) $actor->is_primary_responsible, 'can_prepare' => in_array($record->status, ['confirmee', 'en_location'], true), 'latest_id' => $agreements->first()?->id, 'old_ends_local' => $record->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'initial_total_centimes' => (int) $record->frozen_total_centimes, 'financial' => app(RentalPayment::class)->summary($record), 'history' => $history];
    }
}
