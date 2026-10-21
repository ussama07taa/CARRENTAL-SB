<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RentalPickup
{
    protected function simulationAllowed(CompanyMembership $actor): bool
    {
        return $actor->is_primary_responsible && app()->environment('demo') && DB::connection()->getDatabaseName() === 'bensebih_demo';
    }

    public function summary(Reservation $r, CompanyMembership $actor, ?array $prep = null, ?CarbonImmutable $at = null, bool $simulation = false): array
    {
        $at ??= CarbonImmutable::now();
        $ready = Schema::hasTable('rental_pickups');
        $pickup = $ready ? app(RentalVehicleSwap::class)->pickup($r) : null;
        $prep ??= app(DeparturePreparation::class)->summary($r, $actor, false);
        $financial = app(RentalPayment::class)->summary($r);
        $customerRemaining = $r->active_swap_id ? (app(RentalSettlement::class)->briefs(collect([$r]))[$r->id]['remaining_centimes'] ?? null) : ($financial['remaining_centimes'] ?? null);
        $contract = app(RentalContract::class)->summary($r);
        $simulationAvailable = $this->simulationAllowed($actor) && $contract['demo_only'];
        abort_if($simulation && ! $simulationAvailable, 403, 'La simulation est réservée au responsable dans la base de démonstration.');
        $v = Vehicle::findOrFail($r->vehicle_id);
        $day = $at->setTimezone(RequestPeriod::TIMEZONE)->toDateString();
        $out = Reservation::where('vehicle_id', $r->vehicle_id)->where('status', 'en_location')->where('id', '<>', $r->id)->first();
        $returnPending = in_array($r->vehicle_id, app(RentalReturn::class)->unreadyVehicles(), true);
        $pendingReturn = $returnPending ? DB::table('rental_returns')->where('company_id', Tenant::id())->where('vehicle_id', $r->vehicle_id)->latest('id')->first() : null;
        $returnBlocker = $pendingReturn ? ['reservation_id' => (int) $pendingReturn->reservation_id, 'return_id' => (int) $pendingReturn->id, 'vehicle_id' => (int) $v->id, 'vehicle_name' => $v->make.' '.$v->model, 'plate' => $v->plate] : null;

        $garage = in_array($r->vehicle_id, app(RequestAvailability::class)->garageVehicles(), true);
        $docs = [];
        $docMissing = [];
        foreach (['registration' => 'Carte grise', 'insurance' => 'Assurance', 'inspection' => 'Visite technique', 'circulation' => 'Feuille de circulation'] as $kind => $label) {
            $query = VehicleDocument::where('vehicle_id', $v->id)->where('kind', $kind);
            if ($kind !== 'registration') {
                $query->where(fn ($q) => $q->whereNull('effective_on')->orWhere('effective_on', '<=', $day));
            }
            $doc = $query->latest('version')->first();
            $docs[$kind] = $doc?->only(['id', 'version', 'effective_on', 'expires_on']);
            if (! $doc || (in_array($kind, ['registration', 'circulation'], true) && ! $doc->has_file) || ($kind === 'circulation' && ! $doc->effective_on) || ($kind !== 'registration' && (! $doc->expires_on || $doc->expires_on < $day))) {
                $docMissing[] = $label.' à compléter / renouveler.';
            }
        }
        $tax = VehicleDocument::where('vehicle_id', $v->id)->where('kind', 'tax')->latest('version')->first();
        $docs['tax'] = $tax?->only(['id', 'version', 'expires_on']);
        if ($tax?->expires_on && $tax->expires_on < $day) {
            $docMissing[] = 'Échéance de vignette enregistrée dépassée : mettre à jour le suivi.';
        }
        $customer = Customer::findOrFail($r->customer_id);
        $licenceOk = $customer->licence_expires_on && substr($customer->licence_expires_on, 0, 10) >= $day;
        $signed = $contract['versions'][0]['files'] ?? [];
        $signed = collect($signed)->where('kind', 'signed')->last();
        $window = $at >= ($r->assignment_starts_at ?? $r->starts_at) && $at < $r->ends_at && $at <= CarbonImmutable::now();
        $checks = [
            ['key' => 'reservation', 'label' => 'Réservation confirmée', 'ok' => $r->status === 'confirmee', 'detail' => $r->status === 'en_location' ? 'Départ déjà enregistré.' : 'Le dossier doit être confirmé.', 'target' => null],
            ['key' => 'documents', 'label' => 'Identité et permis vérifiés', 'ok' => $prep['documents']['verified'] && (bool) $licenceOk && app(RentalDriver::class)->summary($r)['verified'], 'detail' => 'Contrôlez les originaux et la validité du permis au jour du départ.', 'target' => 'documents'],
            ['key' => 'payment', 'label' => $r->active_swap_id ? 'Loyer et suppléments convenus reçus et vérifiés' : 'Loyer intégralement reçu et vérifié', 'ok' => ($financial['remaining_centimes'] ?? null) === 0 && $customerRemaining === 0, 'detail' => $r->active_swap_id ? 'Soldez le loyer et les montants client déjà convenus, dont le carburant de la première voiture. Un virement en attente ne compte pas.' : 'Les virements en attente ne comptent pas dans les fonds vérifiés.', 'target' => 'payments'],
            ['key' => 'caution', 'label' => 'Caution couverte ou dispense enregistrée', 'ok' => $prep['caution']['covered'], 'detail' => 'Le dépôt reste séparé du loyer.', 'target' => 'caution'],
            ['key' => 'inspection', 'label' => 'Inspection de départ à jour', 'ok' => $prep['inspection_done'], 'detail' => 'Compteur, carburant, état et photos doivent correspondre à cette voiture.', 'target' => 'inspection'],
            ['key' => 'contract', 'label' => 'Contrat de la bonne version signé et vérifié', 'ok' => $simulation ? $contract['signed_verified'] : $contract['valid_for_pickup'], 'detail' => $contract['demo_only'] ? 'Le modèle actuel est un test : il ne permet pas un départ réel.' : ($contract['stale'] ? 'Le dossier a changé : refaites le contrat.' : 'Ajoutez et vérifiez la copie signée de la dernière version.'), 'target' => 'contract'],
            ['key' => 'vehicle_documents', 'label' => 'Documents du véhicule à jour', 'ok' => empty($docMissing), 'detail' => implode(' ', $docMissing), 'target' => 'vehicle'],
            ['key' => 'vehicle', 'label' => 'Voiture présente et disponible pour la remise', 'ok' => ! $out && ! $garage && ! $returnPending, 'detail' => $out ? 'Un autre départ est encore ouvert. Enregistrez le retour réel avant cette remise.' : ($garage ? 'Retour du garage et disponibilité à contrôler.' : ($returnPending ? 'Un retour ou un dommage attend son contrôle et la validation de disponibilité.' : 'La présence physique sera confirmée à la remise.')), 'target' => 'vehicle'],
            ['key' => 'time', 'label' => 'Remise dans la période convenue', 'ok' => $window, 'detail' => 'Aucun départ futur ni remise hors de la période convenue. Un départ anticipé exige de revoir l’accord.', 'target' => null],
        ];
        $simulationChecks = array_map(fn ($c) => $c['key'] === 'contract' ? [...$c, 'ok' => $contract['signed_verified']] : $c, $checks);
        $review = ['starts_at' => $r->starts_at->toIso8601String(), 'ends_at' => $r->ends_at->toIso8601String(), 'assignment_starts_at' => $r->assignment_starts_at?->toIso8601String(), 'status' => $r->status, 'swap_id' => $r->active_swap_id, 'documents' => $prep['documents']['fingerprint'], 'checked_at' => $prep['documents']['checked_at'], 'caution' => $prep['caution'], 'financial' => $financial, 'customer_remaining_centimes' => $customerRemaining, 'inspection' => $prep['inspection'], 'contract_fingerprint' => $contract['fingerprint'], 'contract_id' => $contract['latest_id'], 'signed_file' => $signed, 'vehicle_version' => $v->version, 'vehicle_documents' => $docs, 'other_out' => $out?->id, 'garage' => $garage, 'return_pending' => $returnPending];

        return ['return_blocker' => $returnBlocker, 'agreement_starts_local' => ($r->assignment_starts_at ?? $r->starts_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'simulation_available' => $simulationAvailable, 'simulation_can_record' => $simulationAvailable && $ready && ! $pickup && collect($simulationChecks)->every(fn ($c) => $c['ok']), 'simulation_review_fingerprint' => hash('sha256', json_encode([$review, 'demo_simulation' => true])), 'ready' => $ready, 'status' => $r->status, 'can_record' => $ready && ! $pickup && collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks, 'review_fingerprint' => $simulation ? hash('sha256', json_encode([$review, 'demo_simulation' => true])) : hash('sha256', json_encode($review)), 'server_now_local' => CarbonImmutable::now(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'starts_local' => $r->starts_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => $r->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'inspection_id' => $prep['inspection']['latest']['id'] ?? null, 'contract_id' => $contract['latest_id'], 'signed_file_id' => $signed['id'] ?? null, 'vehicle_id' => $v->id, 'pickup' => $pickup ? ['id' => $pickup->id, 'picked_up_at' => $pickup->picked_up_at, 'picked_up_local' => CarbonImmutable::parse($pickup->picked_up_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'recorded_at' => $pickup->created_at, 'reason' => $pickup->reason, 'snapshot' => json_decode($pickup->snapshot, true)] : null];
    }

    public function record(int $id, CompanyMembership $actor, array $input): array
    {
        abort_unless(Schema::hasTable('rental_pickups'), 503, 'Le départ attend l’activation de la mise à jour.');
        $simulation = (bool) ($input['demo_simulation'] ?? false);
        abort_if($simulation && ! $this->simulationAllowed($actor), 403, 'La simulation est indisponible dans cet environnement ou pour cet utilisateur.');
        abort_if($simulation && empty($input['simulation_attested']), 422, 'Confirmez une simulation sans remise réelle.');
        $at = app(RequestPeriod::class)->instant($input['picked_up_local'], 'picked_up_local');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $at, $hash, $simulation): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            Vehicle::whereKey($r->vehicle_id)->lockForUpdate()->firstOrFail();
            $old = DB::table('rental_pickups')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à une autre remise.');

                return ['replayed' => true];
            }
            if ($r->status !== 'confirmee') {
                return ['error' => 'UNTRANSITIONABLE', 'message' => 'Ce dossier ne permet pas un nouveau départ.', 'status' => 422];
            }
            $check = $this->summary($r, $actor, null, $at, $simulation);
            if (! $check['can_record']) {
                return ['error' => 'PICKUP_BLOCKED', 'message' => 'La remise des clés est bloquée. Complétez les contrôles indiqués.', 'status' => 422, 'checks' => $check['checks']];
            }
            abort_unless(hash_equals($check['review_fingerprint'], $input['review_fingerprint']), 409, 'Le dossier a changé. Actualisez et revérifiez les contrôles avant de remettre les clés.');
            $agreedStart = $r->assignment_starts_at ?? $r->starts_at;
            $lateMinutes = max(0, (int) $agreedStart->startOfMinute()->diffInMinutes($at->startOfMinute(), false));
            if ($lateMinutes > 0 && (empty($input['late_agreement_confirmed']) || mb_strlen(trim($input['late_agreement_reason'] ?? '')) < 3)) {
                throw ValidationException::withMessages(['late_agreement_confirmed' => 'Le départ prévu est dépassé. Vérifiez les dates avec le client : confirmez le maintien du prix et du retour convenus avec un motif, ou faites revoir l’accord avant la remise.']);
            }
            $lateAgreement = $lateMinutes > 0 ? ['delay_minutes' => $lateMinutes, 'agreed_start' => $agreedStart->toIso8601String(), 'agreed_end' => $r->ends_at->toIso8601String(), 'frozen_total_centimes' => $r->frozen_total_centimes, 'confirmed' => true, 'reason' => trim($input['late_agreement_reason']), 'actor_membership_id' => $actor->id] : null;
            $recordedSimulation = $simulation || config('rental_contracts.isolated_pickup_test', false);
            $inspection = DB::table('rental_departure_inspections')->where('company_id', Tenant::id())->where('id', $check['inspection_id'])->firstOrFail();
            $verification = DB::table('rental_contract_verifications')->where('company_id', Tenant::id())->where('file_id', $check['signed_file_id'])->firstOrFail();
            if ($at->setTimezone(RequestPeriod::TIMEZONE)->toDateString() < $inspection->inspected_on || $at < CarbonImmutable::parse($verification->created_at)->startOfMinute()) {
                throw ValidationException::withMessages(['picked_up_local' => 'La remise doit être postérieure au contrôle et à la vérification du contrat.']);
            }
            $files = DB::table('rental_contract_files')->where('company_id', Tenant::id())->where('contract_id', $check['contract_id'])->where(fn ($q) => $q->where('kind', 'original')->orWhere('id', $check['signed_file_id']))->get();
            abort_unless($files->count() === 2, 422, 'Les deux fichiers du contrat sont nécessaires.');
            foreach ($files as $file) {
                abort_unless(Storage::disk('rental_contracts')->exists($file->path) && hash_equals($file->sha256, hash('sha256', Storage::disk('rental_contracts')->get($file->path))), 422, 'Un fichier du contrat est indisponible ou altéré. La remise reste bloquée.');
            }
            $registration = VehicleDocument::where('vehicle_id', $r->vehicle_id)->where('kind', 'registration')->latest('version')->firstOrFail();
            abort_unless($registration->disk && $registration->path && Storage::disk($registration->disk)->exists($registration->path), 422, 'La carte grise enregistrée est indisponible. Vérifiez le fichier véhicule.');
            $circulation = VehicleDocument::where('vehicle_id', $r->vehicle_id)->where('kind', 'circulation')->where('effective_on', '<=', $at->setTimezone(RequestPeriod::TIMEZONE)->toDateString())->latest('version')->firstOrFail();
            abort_unless($circulation->disk && $circulation->path && Storage::disk($circulation->disk)->exists($circulation->path), 422, 'La feuille de circulation enregistrée est indisponible. Joignez le document avant la remise.');
            $photos = DB::table('rental_inspection_photos')->where('company_id', Tenant::id())->where('inspection_id', $inspection->id)->get();
            abort_if($photos->isEmpty(), 422, 'Les photos du contrôle sont nécessaires.');
            foreach ($photos as $photo) {
                abort_unless(Storage::disk($photo->disk)->exists($photo->path) && hash_equals($photo->sha256, hash('sha256', Storage::disk($photo->disk)->get($photo->path))), 422, 'Une photo du contrôle est indisponible ou altérée.');
            }
            $pickup = DB::table('rental_pickups')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $r->id, 'vehicle_id' => $r->vehicle_id, ...(app(RentalVehicleSwap::class)->ready() ? ['swap_id' => $r->active_swap_id] : []), 'actor_membership_id' => $actor->id, 'inspection_id' => $inspection->id, 'contract_id' => $check['contract_id'], 'signed_file_id' => $check['signed_file_id'], 'picked_up_at' => $at, 'reason' => $input['reason'], 'snapshot' => json_encode(['late_agreement' => $lateAgreement, 'demo_simulation' => $recordedSimulation, 'client_id' => $r->customer_id, 'vehicle_id' => $r->vehicle_id, 'known_damage_ids' => \Illuminate\Support\Facades\Schema::hasTable('damage_tickets') ? app(\App\Support\Vehicles\DamageTickets::class)->query()->where('vehicle_id', $r->vehicle_id)->where('closed', false)->pluck('id')->all() : [], 'kilometers' => (int) $inspection->kilometers, 'fuel_eighths' => (int) $inspection->fuel_eighths, 'inspection_id' => $inspection->id, 'contract_id' => $check['contract_id'], 'signed_file_id' => $check['signed_file_id'], 'review_fingerprint' => $check['review_fingerprint'], 'checks' => $check['checks'], 'keys_handed_over' => true, 'vehicle_present' => true, 'driver_customer_id' => $r->customer_id, 'driver_reviewed' => true]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            DB::table('reservations')->where('company_id', Tenant::id())->where('id', $id)->update(['status' => 'en_location']);
            app(RentalContract::class)->event($id, $actor->id, 'rental_picked_up', ['late_agreement' => $lateAgreement, 'demo_simulation' => $recordedSimulation, 'from' => 'confirmee', 'to' => 'en_location', 'pickup_id' => $pickup, 'picked_up_at' => $at->toIso8601String(), 'reason' => $input['reason']]);

            return ['replayed' => false];
        });
    }
}
