<?php

namespace App\Support\Fines;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Finance\Expenses;
use App\Support\Reservations\RequestPeriod;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FineFiles
{
    public function query(string $table = 'traffic_fines')
    {
        return DB::table($table)->where('company_id', Tenant::id());
    }

    public function actor(int $user): CompanyMembership
    {
        return CompanyMembership::where('user_id', $user)->sole();
    }

    private function lock(): void
    {
        Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
    }

    private function hash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input));
    }

    private function replay(string $table, string $key, string $hash): ?object
    {
        $old = $this->query($table)->where('request_key', $key)->first();
        abort_if($old && ! hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée avec des informations différentes.');

        return $old;
    }

    public function create(array $input, CompanyMembership $actor): int
    {
        $at = app(RequestPeriod::class)->instant($input['occurred_local'], 'occurred_local');
        abort_if($at->isFuture(), 422, 'La date de l’infraction ne peut pas être future.');
        abort_if($input['received_on'] < $at->setTimezone(RequestPeriod::TIMEZONE)->toDateString(), 422, 'La réception ne peut pas précéder l’infraction.');
        $hash = $this->hash($input);

        return DB::transaction(function () use ($input, $actor, $at, $hash) {
            $this->lock();
            if ($old = $this->replay('traffic_fines', $input['request_key'], $hash)) {
                return $old->id;
            }
            Vehicle::findOrFail($input['vehicle_id']);
            $ref = mb_strtoupper(trim($input['authority'])).'|'.mb_strtoupper(trim($input['reference']));
            if ($old = $this->query()->where('reference_key', $ref)->first()) {
                abort(409, 'Cet avis est déjà enregistré : INF-'.$old->id.'. Ouvrez le dossier existant.');
            }

            return DB::table('traffic_fines')->insertGetId(['company_id' => Tenant::id(), 'vehicle_id' => $input['vehicle_id'], 'actor_membership_id' => $actor->id, 'occurred_at' => $at, 'received_on' => $input['received_on'], 'authority' => trim($input['authority']), 'reference' => trim($input['reference']), 'reference_key' => $ref, 'description' => trim($input['description']), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        });
    }

    public function detail(int $id, CompanyMembership $actor): array
    {
        $fine = $this->query()->where('id', $id)->firstOrFail();
        $events = $this->query('traffic_fine_events')->where('fine_id', $id)->orderBy('id')->get();
        $follow = $events->where('action', 'followup')->last();
        $review = $events->where('action', 'rental_review')->last();
        $rental = $review ? DB::table('rental_pickups as p')->where('p.company_id', Tenant::id())->where('p.id', $review->pickup_id)
            ->join('reservations as r', fn ($j) => $j->on('r.company_id', '=', 'p.company_id')->on('r.id', '=', 'p.reservation_id'))
            ->join('customers as c', fn ($j) => $j->on('c.company_id', '=', 'r.company_id')->on('c.id', '=', 'r.customer_id'))
            ->leftJoin('rental_returns as ret', fn ($j) => $j->on('ret.company_id', '=', 'p.company_id')->on('ret.pickup_id', '=', 'p.id'))
            ->first(['r.id as reservation_id', 'c.id as customer_id', 'c.first_name', 'c.last_name', 'c.phone', 'c.address', 'c.city', 'c.identity_type', 'c.identity_number', 'c.licence_number', 'p.picked_up_at', 'ret.returned_at']) : null;
        $actors = DB::table('company_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.company_id', Tenant::id())->pluck('u.name', 'm.id');
        $expense = $events->where('action', 'expense')->last();
        $declaration = $events->where('action', 'declaration')->last();
        $direct = $events->where('action', 'client_direct')->last();
        $handling = fn ($e) => $e ? [...json_decode($e->handling, true), 'evidence_document_id' => $e->evidence_document_id, 'note' => $e->note] : null;

        return ['id' => $id, 'vehicle' => Vehicle::findOrFail($fine->vehicle_id)->only(['id', 'make', 'model', 'plate']),
            'occurred_local' => CarbonImmutable::parse($fine->occurred_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
            'received_on' => $fine->received_on, 'authority' => $fine->authority, 'reference' => $fine->reference, 'description' => $fine->description,
            'status' => $follow?->status ?? 'open', 'next_on' => $follow?->next_on, 'next_action' => $follow?->note, 'revision' => (int) ($events->last()?->id ?? 0),
            'declaration' => $handling($declaration),
            'client_direct' => $actor->is_primary_responsible ? $handling($direct) : ($direct ? ['recorded' => true] : null),
            'has_agency_expense' => (bool) $expense,
            'rental' => $rental, 'can_manage_finance' => (bool) $actor->is_primary_responsible,
            'finance' => $actor->is_primary_responsible && $expense ? app(Expenses::class)->detail($expense->expense_id) : null,
            'documents' => $this->query('traffic_fine_documents')->where('fine_id', $id)->orderBy('id')->get(['id', 'mime', 'size', 'created_at']),
            'history' => $events->filter(fn ($e) => $actor->is_primary_responsible || ! in_array($e->action, ['expense', 'client_direct'], true))->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'status' => $e->status, 'note' => $e->note, 'next_on' => $e->next_on, 'actor' => $actors[$e->actor_membership_id] ?? '', 'created_at' => $e->created_at])->values(),
            'created_at' => $fine->created_at];
    }

    public function event(int $id, array $input, CompanyMembership $actor): void
    {
        $hash = $this->hash([...$input, 'fine_id' => $id]);
        DB::transaction(function () use ($id, $input, $actor, $hash) {
            $this->lock();
            $fine = $this->query()->where('id', $id)->firstOrFail();
            if ($this->replay('traffic_fine_events', $input['request_key'], $hash)) {
                return;
            }
            $events = $this->query('traffic_fine_events')->where('fine_id', $id);
            abort_unless((int) ($events->max('id') ?? 0) === (int) $input['expected_revision'], 409, 'Le dossier a changé. Actualisez avant de continuer.');
            $expenseId = null;
            $pickupId = null;
            $handling = null;
            $evidence = null;
            if (in_array($input['action'], ['declaration', 'client_direct'], true)) {
                abort_unless($actor->is_primary_responsible, 403);
                abort_unless((clone $events)->where('action', 'rental_review')->exists(), 422, 'Vérifiez d’abord le rapprochement avec la location.');
                abort_if($input['effective_on'] < CarbonImmutable::parse($fine->occurred_at)->setTimezone(RequestPeriod::TIMEZONE)->toDateString(), 422, 'La démarche ne peut pas précéder l’infraction.');
                $stage = $input['action'] === 'declaration' ? $input['handling_stage'] : 'paid';
                $evidence = $input['evidence_document_id'] ?? null;
                if ($stage !== 'prepared') {
                    abort_unless($evidence, 422, 'Joignez et sélectionnez le justificatif avant de confirmer.');
                }
                if ($evidence) {
                    abort_unless($this->query('traffic_fine_documents')->where('fine_id', $id)->where('id', $evidence)->exists(), 422, 'Cette pièce ne fait pas partie de cet avis.');
                }
                if ($input['action'] === 'client_direct') {
                    abort_if((clone $events)->where('action', 'expense')->exists(), 409, 'Une dépense agence est déjà liée : vérifiez-la avant de déclarer un paiement direct du client. Aucun paiement existant ne sera effacé.');
                    abort_if((clone $events)->where('action', 'client_direct')->exists(), 409, 'Le paiement direct est déjà enregistré. Consultez son justificatif.');
                }
                $pickupId = (clone $events)->where('action', 'rental_review')->orderByDesc('id')->value('pickup_id');
                $handling = json_encode(['stage' => $stage, 'person_name' => $input['person_name'], 'effective_on' => $input['effective_on'], 'recipient' => $input['recipient'] ?? null, 'external_reference' => $input['external_reference'] ?? null]);
            }
            if ($input['action'] === 'rental_review') {
                $pickupId = $input['pickup_id'];
                $match = DB::table('rental_pickups as p')->where('p.company_id', Tenant::id())->where('p.id', $pickupId)->where('p.vehicle_id', $fine->vehicle_id)
                    ->leftJoin('rental_returns as ret', fn ($j) => $j->on('ret.company_id', '=', 'p.company_id')->on('ret.pickup_id', '=', 'p.id'))
                    ->where('p.picked_up_at', '<=', $fine->occurred_at)->where(fn ($q) => $q->whereNull('ret.returned_at')->orWhere('ret.returned_at', '>', $fine->occurred_at))->exists();
                abort_unless($match, 422, 'Cette remise de clés ne correspond pas à la voiture et à l’heure de l’avis.');
            }
            if ($input['action'] === 'expense') {
                abort_if((clone $events)->where('action', 'client_direct')->exists(), 409, 'Le client a déjà payé directement : aucune dépense agence à créer.');
                abort_unless($actor->is_primary_responsible, 403, 'Le paiement de l’avis est réservé au responsable.');
                abort_if((clone $events)->where('action', 'expense')->exists(), 409, 'Une dépense est déjà liée. Ouvrez-la pour enregistrer le paiement.');
                if (! empty($input['expense_id'])) {
                    $expense = app(Expenses::class)->detail($input['expense_id']);
                    abort_if($expense['cancelled'] || count($expense['allocations']) !== 1 || (int) $expense['allocations'][0]['vehicle_id'] !== (int) $fine->vehicle_id, 422, 'La dépense doit concerner uniquement cette voiture.');
                    abort_unless(mb_strtoupper(trim($expense['invoice_reference'] ?? '')) === mb_strtoupper($fine->reference) && mb_strtoupper(trim($expense['supplier'])) === mb_strtoupper($fine->authority), 422, 'La référence et le destinataire de la dépense doivent correspondre à cet avis.');
                    $expenseId = $expense['id'];
                } else {
                    abort_if(! empty($input['due_on']) && $input['due_on'] < $fine->received_on, 422, 'L’échéance ne peut pas précéder la réception de cet avis.');
                    $expenseId = app(Expenses::class)->save(null, $actor->id, ['title' => 'Infraction '.$fine->reference, 'category' => 'other', 'supplier' => $fine->authority, 'invoice_reference' => $fine->reference, 'incurred_on' => $fine->received_on, 'due_on' => $input['due_on'] ?? null, 'amount_centimes' => $input['amount_centimes'], 'allocations' => [['vehicle_id' => $fine->vehicle_id, 'amount_centimes' => $input['amount_centimes']]], 'paid_centimes' => 0, 'notes' => 'Avis INF-'.$id.'. Paiement à l’autorité ; aucune dette client automatique.', 'request_key' => $input['request_key']]);
                }
            }
            DB::table('traffic_fine_events')->insert(['company_id' => Tenant::id(), 'fine_id' => $id, 'actor_membership_id' => $actor->id, 'action' => $input['action'], 'handling' => $handling, 'evidence_document_id' => $evidence, 'status' => $input['action'] === 'followup' ? $input['status'] : null, 'note' => trim($input['note']), 'next_on' => $input['action'] === 'followup' && $input['status'] !== 'closed' ? ($input['next_on'] ?? null) : null, 'pickup_id' => $pickupId, 'expense_id' => $expenseId, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        });
    }

    public function document(int $id, UploadedFile $file, string $key, CompanyMembership $actor): void
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = null;
        try {
            DB::transaction(function () use ($id, $file, $key, $actor, $checksum, &$path) {
                $this->lock();
                $this->query()->where('id', $id)->firstOrFail();
                if ($old = $this->query('traffic_fine_documents')->where('request_key', $key)->first()) {
                    abort_unless((int) $old->fine_id === $id && hash_equals($old->checksum, $checksum), 409, 'Ce justificatif correspond à une autre opération.');

                    return;
                }
                if ($this->query('traffic_fine_documents')->where('fine_id', $id)->where('checksum', $checksum)->exists()) {
                    return;
                }
                $path = $file->store(Tenant::id().'/'.$id, 'fine_documents');
                DB::table('traffic_fine_documents')->insert(['company_id' => Tenant::id(), 'fine_id' => $id, 'actor_membership_id' => $actor->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'checksum' => $checksum, 'request_key' => $key, 'created_at' => now()]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('fine_documents')->delete($path);
            }throw $e;
        }
    }
}
