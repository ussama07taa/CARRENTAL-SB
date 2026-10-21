<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeparturePreparation
{
    public function ready(): bool
    {
        return Schema::hasTable('reservation_departure_entries');
    }

    private function entries(int $id)
    {
        return DB::table('reservation_departure_entries')->where('company_id', Tenant::id())->where('reservation_id', $id);
    }

    private function documents(Reservation $record): array
    {
        $customer = Customer::findOrFail($record->customer_id);
        $fields = $customer->only(['id', 'first_name', 'last_name', 'identity_type', 'identity_number', 'licence_number', 'licence_expires_on']);
        $documents = CustomerDocument::where('customer_id', $customer->id)->orderByDesc('version')->get()->unique('slot')->sortBy('slot')->values();
        $snapshot = ['customer' => $fields, 'documents' => $documents->map->only(['id', 'slot', 'version'])->all()];
        $missing = [];
        if (! $customer->identity_type || ! $customer->identity_number) {
            $missing[] = 'Type et numéro de la pièce d’identité à compléter.';
        }
        if (! $customer->licence_number || ! $customer->licence_expires_on) {
            $missing[] = 'Numéro et date de validité du permis à compléter.';
        } elseif (substr((string) $customer->licence_expires_on, 0, 10) < $record->starts_at->setTimezone(RequestPeriod::TIMEZONE)->toDateString()) {
            $missing[] = 'Le permis expire avant la date de départ.';
        }

        return ['snapshot' => $snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot)), 'missing' => $missing, 'files' => $documents];
    }

    public function summary(Reservation $record, CompanyMembership $actor, bool $includePickup = true): array
    {
        $docs = $this->documents($record);
        $ready = $this->ready();
        $entries = $ready ? $this->entries($record->id)->orderBy('id')->get() : collect();
        $checked = $entries->where('action', 'documents_checked')->last();
        $checkedData = $checked ? json_decode($checked->data, true) : null;
        $documentsOk = $checked && empty($docs['missing']) && hash_equals($checkedData['fingerprint'], $docs['fingerprint']);
        $additional = app(RentalDriver::class)->summary($record);
        $terms = $entries->whereIn('action', ['caution_set', 'caution_waived'])->last();
        $waived = $terms?->action === 'caution_waived';
        $required = $terms?->action === 'caution_set' ? (int) $terms->amount_centimes : null;
        $verifiedIds = $entries->where('action', 'caution_verified')->pluck('receipt_id')->all();
        $receipts = $entries->where('action', 'caution_received');
        $verified = (int) $receipts->filter(fn ($r) => $r->method !== 'virement' || in_array($r->id, $verifiedIds, true))->sum('amount_centimes');
        $pending = (int) $receipts->filter(fn ($r) => $r->method === 'virement' && ! in_array($r->id, $verifiedIds, true))->sum('amount_centimes');
        $used = app(RentalSettlement::class)->ready() ? (int) app(RentalSettlement::class)->entries($record->id)->whereIn('kind', ['deposit_return', 'deposit_allocate'])->sum('amount_centimes') : 0;
        $verified -= $used;
        $cautionOk = $waived || ($required !== null && $verified >= $required);
        $financial = app(RentalPayment::class)->summary($record);
        $missing = $docs['missing'];
        if (! $additional['verified']) {
            $missing[] = 'Vérifier les originaux du conducteur supplémentaire avant de préparer le contrat.';
            $missing = [...$missing, ...$additional['missing']];
        }
        if (! $documentsOk) {
            $missing[] = $checked ? 'Identité ou permis modifié : vérifier à nouveau les originaux.' : 'Vérifier les originaux de l’identité et du permis.';
        }
        if (! $cautionOk) {
            $missing[] = $required === null ? 'Définir la caution ou enregistrer une dispense du responsable.' : 'Caution à recevoir / bloquer et vérifier : '.number_format(max(0, $required - $verified) / 100, 2, ',', ' ').' DH.';
        }
        if (($financial['remaining_centimes'] ?? null) !== 0) {
            $missing[] = 'Le loyer doit être intégralement reçu et vérifié.';
        }
        $garage = in_array($record->vehicle_id, app(RequestAvailability::class)->garageVehicles(), true);
        if ($garage) {
            $missing[] = 'Voiture au garage : retour et disponibilité opérationnelle à contrôler.';
        }
        $inspection = app(DepartureInspection::class)->summary($record);
        if (! $inspection['completed']) {
            $missing[] = $inspection['blocking_anomaly'] ? 'Anomalie bloquante : résoudre puis enregistrer un nouveau contrôle.' : ($inspection['stale'] ? 'Compteur ou voiture modifié : refaire le contrôle de départ.' : 'Inspection de départ à enregistrer.');
        }
        $contract = app(RentalContract::class)->summary($record);
        if (! $contract['valid_for_pickup']) {
            $missing[] = $contract['signed_verified'] ? 'Copie de test vérifiée : un modèle de contrat validé reste nécessaire avant un départ réel.' : ($contract['stale'] ? 'Données modifiées : refaire le contrat et vérifier sa nouvelle copie signée.' : 'Contrat signé à enregistrer et vérifier.');
        }

        $result = ['ready' => $ready, 'confirmed' => $record->status === 'confirmee', 'can_manage_documents' => (bool) $actor->is_primary_responsible, 'can_waive' => (bool) $actor->is_primary_responsible, 'can_verify_transfer' => (bool) $actor->is_primary_responsible,
            'documents' => ['customer' => [...$docs['snapshot']['customer'], 'version' => Customer::findOrFail($record->customer_id)->version], 'fingerprint' => $docs['fingerprint'], 'files' => $actor->is_primary_responsible ? $docs['files'] : [], 'missing' => $docs['missing'], 'verified' => (bool) $documentsOk, 'checked_at' => $checked?->created_at],
            'caution' => ['terms_id' => $terms?->id, 'required_centimes' => $required, 'waived' => $waived, 'reason' => $terms?->reason, 'verified_centimes' => $verified, 'pending_centimes' => $pending, 'remaining_centimes' => $required === null ? null : max(0, $required - $verified), 'surplus_centimes' => $required === null ? 0 : max(0, $verified - $required), 'covered' => $cautionOk, 'receipts' => $receipts->map(fn ($r) => ['id' => $r->id, 'amount_centimes' => (int) $r->amount_centimes, 'method' => $r->method, 'received_on' => $r->received_on, 'reference' => $r->reference, 'reason' => $r->reason, 'verified' => $r->method !== 'virement' || in_array($r->id, $verifiedIds, true)])->values()->all()],
            'missing' => $missing, 'can_pick_up' => false, 'inspection' => $inspection, 'inspection_done' => $inspection['completed'], 'contract' => collect($contract)->only(['ready', 'stale', 'signed_verified', 'valid_for_pickup', 'latest_id', 'demo_only'])->all(), 'contract_signed' => $contract['valid_for_pickup']];
        if ($includePickup) {
            $pickup = app(RentalPickup::class)->summary($record, $actor, $result);
            $result['can_pick_up'] = $pickup['can_record'];
            $result['pickup'] = collect($pickup)->only(['ready', 'can_record', 'status', 'pickup'])->all();
        }

        $result['additional_driver_verified'] = $additional['verified'];
        return $result;
    }

    public function save(int $id, CompanyMembership $actor, string $action, array $input): array
    {
        abort_unless($this->ready(), 503, 'La préparation du départ attend l’activation de la mise à jour.');
        if (in_array($action, ['caution_waived', 'caution_verified'], true)) {
            abort_unless($actor->is_primary_responsible, 403, 'Cette action est réservée au responsable.');
        }

        return DB::transaction(function () use ($id, $actor, $action, $input): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$id, $action, $input]));
            $old = DB::table('reservation_departure_entries')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée avec d’autres données.');

                return ['replayed' => true];
            }
            abort_unless($record->status === 'confirmee' || ($action === 'caution_verified' && in_array($record->status, ['en_location', 'retournee', 'annulee'], true)), 422, 'Confirmez la réservation avant de préparer le départ.');
            $data = [];
            if ($action === 'documents_checked') {
                Customer::whereKey($record->customer_id)->lockForUpdate()->firstOrFail();
                $docs = $this->documents($record);
                abort_unless(hash_equals($docs['fingerprint'], $input['fingerprint']), 409, 'Les documents ont changé. Actualisez puis vérifiez à nouveau les originaux.');
                abort_if(count($docs['missing']) > 0, 422, implode(' ', $docs['missing']));
                $data = ['fingerprint' => $docs['fingerprint'], 'snapshot' => $docs['snapshot'], 'identity_original' => true, 'licence_original' => true];
            } elseif (in_array($action, ['caution_set', 'caution_waived'], true)) {
                $current = $this->entries($id)->whereIn('action', ['caution_set', 'caution_waived'])->max('id');
                abort_unless((int) $current === (int) ($input['expected_terms_id'] ?? 0), 409, 'La caution a changé. Actualisez avant de modifier.');
            } elseif ($action === 'caution_verified') {
                $receipt = $this->entries($id)->where('id', $input['receipt_id'])->where('action', 'caution_received')->first();
                abort_unless($receipt, 404);
                abort_unless($receipt->method === 'virement', 422, 'Seuls les virements en attente nécessitent ce contrôle.');
                abort_if($this->entries($id)->where('action', 'caution_verified')->where('receipt_id', $receipt->id)->exists(), 409, 'Cette réception a déjà été vérifiée.');
            }
            $entry = DB::table('reservation_departure_entries')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'action' => $action, 'amount_centimes' => $input['amount_centimes'] ?? null, 'receipt_id' => $input['receipt_id'] ?? null, 'method' => $input['method'] ?? null, 'received_on' => $input['received_on'] ?? null, 'reference' => $input['reference'] ?? null, 'reason' => $input['reason'], 'data' => json_encode($data), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'action' => $action, 'data' => json_encode(['departure_entry_id' => $entry, 'amount_centimes' => $input['amount_centimes'] ?? null, 'reason' => $input['reason']]), 'created_at' => now()]);

            return ['replayed' => false];
        });
    }
}
