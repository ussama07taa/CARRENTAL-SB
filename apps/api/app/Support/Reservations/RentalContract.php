<?php

namespace App\Support\Reservations;

use App\Jobs\RenderRentalContract;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RentalContract
{
    public function table(string $table): Builder
    {
        return DB::table($table)->where('company_id', Tenant::id());
    }

    public function snapshot(Reservation $r): array
    {
        $customer = Customer::findOrFail($r->customer_id);
        $caution = Schema::hasTable('reservation_departure_entries') ? $this->table('reservation_departure_entries')->where('reservation_id', $r->id)->where('action', 'like', 'caution_%')->orderBy('id')->get(['id', 'action', 'amount_centimes', 'receipt_id', 'method', 'received_on', 'reference', 'reason'])->all() : [];

        $swap = $r->active_swap_id ? $this->table('rental_vehicle_swaps')->where('reservation_id', $r->id)->where('id', $r->active_swap_id)->firstOrFail() : null;
        $extension = app(RentalExtension::class)->activeAgreement($r);
        $financial = ($swap || $extension) ? app(RentalPayment::class)->summary($r) : null;
        $cautionUsed = $swap && app(RentalSettlement::class)->ready() ? (int) app(RentalSettlement::class)->entries($r->id)->whereIn('kind', ['deposit_return', 'deposit_allocate'])->sum('amount_centimes') : 0;

        return [...app(RentalDriver::class)->contractFields($r), ...($extension ? ['extension' => app(RentalExtension::class)->context($extension)] : []), ...($swap ? ['caution_used_centimes' => $cautionUsed, 'vehicle_swap' => ['id' => $swap->id, 'difference_centimes' => (int) $swap->difference_centimes, 'reason' => $swap->reason, 'snapshot' => json_decode($swap->snapshot, true)]] : []), 'model' => config('rental_contracts.model_version'), 'demo' => (bool) config('rental_contracts.demo_only'), 'language' => $customer->language === 'ar' ? 'ar' : 'fr', 'agency' => ['name' => Company::findOrFail(Tenant::id())->name], 'reservation' => ['id' => $r->id, 'starts_at' => ($r->assignment_starts_at ?? $r->starts_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d H:i'), 'ends_at' => $r->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d H:i'), 'total_centimes' => (int) ($financial['total_centimes'] ?? $r->frozen_total_centimes)], 'customer' => $customer->only(['id', 'first_name', 'last_name', 'phone', 'address', 'identity_type', 'identity_number', 'licence_number', 'licence_expires_on']), 'documents' => CustomerDocument::where('customer_id', $customer->id)->orderBy('id')->get(['id', 'slot', 'version'])->all(), 'vehicle' => Vehicle::findOrFail($r->vehicle_id)->only(['id', 'plate', 'make', 'model', 'vin', 'year']), 'caution' => $caution, 'inspection' => app(DepartureInspection::class)->summary($r)['latest']];
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot));
    }

    /** Freeze known display information when issuing, without changing previous fingerprints. */
    public function presentation(Reservation $reservation): array
    {
        $financial = app(RentalPayment::class)->summary($reservation);
        $methods = collect($financial['payments'] ?? [])->where('status', 'verified')->pluck('method')->unique()->values()->all();
        $pricing = $financial['pricing_snapshot'] ?? null;
        $lines = ($pricing['agreement_mode'] ?? null) === 'calculated' ? ($pricing['lines'] ?? []) : [];
        $rates = collect($lines)->pluck('daily_centimes')->unique();

        return ['layout' => 4, 'pricing_half_day_units' => ! $reservation->active_swap_id && isset($pricing['lines']) && count($pricing['lines']) > 0 ? collect($pricing['lines'])->sum('half_day_units') + ($reservation->active_extension_id ? app(RentalExtension::class)->halfDayAddition($reservation->id) : 0) : null, 'pricing_agreement_mode' => $pricing['agreement_mode'] ?? null, 'fuel' => Vehicle::findOrFail($reservation->vehicle_id)->fuel,
            'verified_payment_methods' => $methods, 'verified_centimes' => $financial['verified_centimes'] ?? null,
            'remaining_centimes' => $financial['remaining_centimes'] ?? null,
            'agreed_daily_centimes' => $rates->count() === 1 ? $rates->first() : null,
            'first_daily_centimes' => $lines[0]['daily_centimes'] ?? null,
            'last_daily_centimes' => $lines ? $lines[array_key_last($lines)]['daily_centimes'] : null];
    }

    public function summary(Reservation $r): array
    {
        $snapshot = $this->snapshot($r);
        $ready = Schema::hasTable('rental_contract_versions');
        $allVersions = $ready ? $this->table('rental_contract_versions')->where('reservation_id', $r->id)->orderByDesc('version')->get() : collect();
        $activated = app(RentalExtension::class)->ready() ? $this->table('rental_extension_activations')->where('reservation_id', $r->id)->pluck('agreement_id')->all() : [];
        $versions = $allVersions->filter(fn ($v) => empty($v->extension_id) || in_array($v->extension_id, $activated, true))->values();
        $files = $ready ? $this->table('rental_contract_files')->where('reservation_id', $r->id)->orderBy('id')->get() : collect();
        $checks = $ready ? $this->table('rental_contract_verifications')->where('reservation_id', $r->id)->pluck('file_id')->all() : [];
        $latest = $versions->first();
        $fingerprint = $this->fingerprint($snapshot);
        $stale = $latest && ! hash_equals($latest->fingerprint, $fingerprint);
        $signed = $latest ? $files->where('contract_id', $latest->id)->where('kind', 'signed')->last() : null;
        $verified = $signed && in_array($signed->id, $checks, true);

        return ['latest_version_id' => $allVersions->first()?->id, 'can_sign_rectification' => $this->canSignRectification($r, $latest) && ! $stale, 'tablet_signing_enabled' => (bool) config('rental_contracts.tablet_signing_enabled', false) && CompanyMembership::where('user_id', auth()->id())->where('is_primary_responsible', true)->exists(), 'ready' => $ready, 'demo_only' => (bool) config('rental_contracts.demo_only'), 'preview' => $snapshot, 'fingerprint' => $fingerprint, 'latest_id' => $latest?->id, 'stale' => (bool) $stale, 'signed_verified' => (bool) ($verified && ! $stale), 'valid_for_pickup' => (bool) ($verified && ! $stale && ! $snapshot['demo']), 'versions' => $versions->map(function ($v) use ($files, $checks): array {
            $own = $files->where('contract_id', $v->id);
            $original = $own->where('kind', 'original')->first();
            $attempt = $this->table('rental_contract_render_events')->where('contract_id', $v->id)->latest('id')->value('action');

            return ['extension_id' => $v->extension_id ?? null, 'layout' => data_get(json_decode($v->snapshot, true), 'presentation.layout'), 'id' => $v->id, 'version' => $v->version, 'previous_id' => $v->previous_id, 'reference' => 'CTR-'.str_pad((string) $v->reservation_id, 6, '0', STR_PAD_LEFT).'-V'.$v->version, 'created_at' => $v->created_at, 'reason' => $v->reason, 'status' => $original ? 'ready' : ($attempt === 'failed' ? 'failed' : 'pending'), 'files' => $own->map(fn ($f) => ['id' => $f->id, 'kind' => $f->kind, 'mime' => $f->mime, 'sha256' => $f->sha256, 'verified' => in_array($f->id, $checks, true)])->values()->all()];
        })->all()];
    }

    private function rectificationEnvironment(): bool
    {
        return app()->environment('testing')
            || (app()->environment('demo') && DB::connection()->getDatabaseName() === 'bensebih_demo')
            || (app()->environment('local') && DB::connection()->getDatabaseName() === 'bensebih_pickup_sandbox_20261019');
    }

    private function canSignRectification(Reservation $r, ?object $version): bool
    {
        return in_array($r->status, ['en_location', 'retournee'], true) && $version
            && data_get(json_decode($version->snapshot, true), 'rectification.kind') === 'duration_display'
            && $this->rectificationEnvironment()
            && CompanyMembership::where('user_id', auth()->id())->where('is_primary_responsible', true)->exists();
    }

    public function assertCurrent(Reservation $r, int $id): object
    {
        $v = $this->table('rental_contract_versions')->where('reservation_id', $r->id)->where('id', $id)->firstOrFail();
        if (! empty($v->extension_id) && ! app(RentalExtension::class)->table('rental_extension_activations')->where('agreement_id', $v->extension_id)->exists()) {
            $extensions = app(RentalExtension::class);
            $agreement = $extensions->agreement($r->id, $v->extension_id);
            $extensions->assertApplicable($r, $agreement);
            abort_unless($v->id === $this->table('rental_contract_versions')->where('reservation_id', $r->id)->where('extension_id', $agreement->id)->latest('version')->value('id'), 409, 'Reprenez la dernière version de cet avenant.');
            $snapshot = $extensions->contractSnapshot($r, $agreement);
        } else {
            $latest = $this->summary($r)['latest_id'];
            abort_unless(($r->status === 'confirmee' || $this->canSignRectification($r, $v)) && $v->id === $latest, 409, 'Cette version n’est plus la version courante du dossier.');
            $snapshot = $this->snapshot($r);
        }
        abort_unless(hash_equals($v->fingerprint, $this->fingerprint($snapshot)), 409, 'Les informations ont changé. Créez une nouvelle version avant de signer.');

        return $v;
    }

    public function event(int $reservation, int $actor, string $action, array $data): void
    {
        DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'actor_membership_id' => $actor, 'action' => $action, 'data' => json_encode($data), 'created_at' => now()]);
    }

    public function enqueue(int $id): void
    {
        $this->table('rental_contract_render_events')->insert(['company_id' => Tenant::id(), 'contract_id' => $id, 'action' => 'requested', 'created_at' => now()]);
        RenderRentalContract::dispatch(Tenant::id(), $id)->onQueue(config('rental_contracts.queue'))->afterCommit();
    }

    public function issue(int $reservation, CompanyMembership $actor, array $input): array
    {
        abort_unless(Schema::hasTable('rental_contract_versions'), 503, 'Le contrat attend l’activation de la mise à jour.');
        abort_unless(config('rental_contracts.demo_only') && app()->environment(['demo', 'testing', 'local']), 422, 'Le modèle de contrat réel doit être validé avant émission.');
        $hash = hash('sha256', json_encode([$reservation, $input]));

        return DB::transaction(function () use ($reservation, $actor, $input, $hash): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
            $old = $this->table('rental_contract_versions')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à d’autres données.');

                return ['id' => $old->id, 'replayed' => true];
            }
            $rectification = (bool) ($input['duration_rectification'] ?? false);
            $extension = isset($input['extension_id']) ? app(RentalExtension::class)->agreement($reservation, (int) $input['extension_id']) : null;
            abort_if($extension && $rectification, 422, 'Choisissez un seul type de correction.');
            if ($extension) {
                abort_unless($actor->is_primary_responsible, 403);
                app(RentalExtension::class)->assertApplicable($r, $extension);
                $funds = app(RentalExtension::class)->funds($r, $extension);
                abort_unless($funds['remaining_centimes'] === 0 && $funds['current_remaining_centimes'] === 0, 422, 'Recevez et vérifiez le supplément avant de préparer l’avenant.');
            } elseif ($rectification) {
                abort_unless($actor->is_primary_responsible && $this->rectificationEnvironment(), 403);
                abort_unless($r->status === 'en_location', 422, 'Cette correction est réservée à un contrat de location déjà sortie.');
            } else {
                abort_unless($r->status === 'confirmee', 422, 'Confirmez la réservation avant de préparer le contrat.');
            }
            $prep = $extension ? null : app(DeparturePreparation::class)->summary($r, $actor);
            abort_unless(app(RentalDriver::class)->summary($r)['verified'], 422, 'Vérifiez les originaux du conducteur supplémentaire avant le contrat.');
            abort_unless($extension || ($prep['documents']['verified'] && $prep['caution']['covered'] && $prep['inspection_done'] && app(RentalPayment::class)->summary($r)['remaining_centimes'] === 0), 422, 'Complétez les documents, le paiement, la caution et l’inspection avant le contrat.');
            $previous = $this->table('rental_contract_versions')->where('reservation_id', $reservation)->latest('version')->first();
            abort_unless((int) ($extension ? ($previous?->id ?? 0) : ($this->summary($r)['latest_id'] ?? 0)) === (int) ($input['expected_latest_id'] ?? 0), 409, 'Une autre version existe. Actualisez le dossier.');
            $snapshot = $extension ? app(RentalExtension::class)->contractSnapshot($r, $extension) : $this->snapshot($r);
            $fingerprint = $this->fingerprint($snapshot);
            abort_unless(hash_equals($fingerprint, $input['fingerprint']), 409, 'Les informations ont changé. Actualisez la revue du contrat.');
            if ($rectification) {
                abort_unless($previous && hash_equals($previous->fingerprint, $fingerprint), 409, 'Le dossier a changé : cette correction ne peut pas modifier les informations du contrat émis.');
                $snapshot = json_decode($previous->snapshot, true, 512, JSON_THROW_ON_ERROR);
                abort_if(isset($snapshot['rectification']), 422, 'La version corrigée existe déjà. Reprenez sa signature.');
                $pricing = app(RentalPayment::class)->summary($r)['pricing_snapshot'] ?? null;
                $units = isset($pricing['lines']) ? collect($pricing['lines'])->sum('half_day_units') : 0;
                abort_unless(is_int($units) && $units > 0 && isset($snapshot['presentation']), 422, 'La référence tarifaire enregistrée est nécessaire pour cette correction.');
                $snapshot['presentation']['pricing_half_day_units'] = $units;
                $snapshot['presentation']['pricing_agreement_mode'] = $pricing['agreement_mode'];
                $snapshot['rectification'] = ['kind' => 'duration_display', 'previous_contract_id' => $previous->id, 'previous_reference' => 'CTR-'.str_pad((string) $reservation, 6, '0', STR_PAD_LEFT).'-V'.$previous->version, 'price_and_period_unchanged' => true];
            } else {
                $snapshot['presentation'] = $this->presentation($r);
                if ($extension) {
                    $units = collect(data_get(json_decode($extension->snapshot, true), 'pricing.lines', []))->sum('half_day_units');
                    if ($snapshot['presentation']['pricing_half_day_units'] !== null) {
                        $snapshot['presentation']['pricing_half_day_units'] += $units;
                    }
                }
            }
            $id = $this->table('rental_contract_versions')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'actor_membership_id' => $actor->id, ...(app(RentalExtension::class)->ready() ? ['extension_id' => $extension?->id] : []), 'version' => ($previous?->version ?? 0) + 1, 'previous_id' => $previous?->id, 'snapshot' => json_encode($snapshot), 'fingerprint' => $fingerprint, 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $this->event($reservation, $actor->id, $extension ? 'extension_contract_requested' : ($rectification ? 'contract_duration_corrected' : 'contract_requested'), ['contract_id' => $id, 'previous_id' => $previous?->id, 'reason' => $input['reason']]);
            $this->enqueue($id);

            return ['id' => $id, 'replayed' => false];
        });
    }
}
