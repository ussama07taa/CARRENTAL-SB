<?php

namespace App\Support\Reservations;

use App\Jobs\RenderForeignAuthorization;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ForeignTravel
{
    public function events(int $id): Builder
    {
        return DB::table('reservation_events')->where('company_id', Tenant::id())->where('reservation_id', $id)->where('action', 'like', 'foreign_%');
    }

    public function context(Reservation $r): array
    {
        return ['customer' => Customer::findOrFail($r->customer_id)->only(['id', 'first_name', 'last_name', 'address', 'identity_type', 'identity_number', 'licence_number', 'licence_expires_on', 'language']),
            'vehicle' => Vehicle::findOrFail($r->vehicle_id)->only(['id', 'make', 'model', 'plate', 'vin']),
            'starts_at' => $r->starts_at->toIso8601String(), 'ends_at' => $r->ends_at->toIso8601String()];
    }

    public function sheet(Reservation $r, ?string $start, ?string $end): ?VehicleDocument
    {
        if (! $start || ! $end) {
            return null;
        }
        $sheet = VehicleDocument::where('vehicle_id', $r->vehicle_id)->where('kind', 'circulation')->where('effective_on', '<=', substr($start, 0, 10))->latest('version')->first();

        return $sheet && $sheet->expires_on >= substr($end, 0, 10) ? $sheet : null;
    }

    public function circulation(Reservation $r): array
    {
        $latest = VehicleDocument::where('vehicle_id', $r->vehicle_id)->where('kind', 'circulation')->latest('version')->first();
        $current = $latest?->effective_on > now(RequestPeriod::TIMEZONE)->toDateString() ? VehicleDocument::where('vehicle_id', $r->vehicle_id)->where('kind', 'circulation')->where('effective_on', '<=', now(RequestPeriod::TIMEZONE)->toDateString())->latest('version')->first() : $latest;

        return ['latest' => $latest?->only(['id', 'effective_on', 'expires_on', 'deadline', 'has_file']), 'current' => $current?->only(['id', 'effective_on', 'expires_on', 'deadline']),
            'expires_during_rental' => (bool) ($latest && $latest->expires_on < $r->ends_at->setTimezone(RequestPeriod::TIMEZONE)->toDateString()), 'vehicle_id' => $r->vehicle_id];
    }

    public function summary(Reservation $r, CompanyMembership $actor): array
    {
        $events = $this->events($r->id)->orderBy('id')->get();
        $latest = $events->where('action', 'foreign_requested')->last();
        $req = $latest ? json_decode($latest->data, true) : null;
        $decision = $latest ? $events->filter(fn ($e) => in_array($e->action, ['foreign_approved', 'foreign_refused', 'foreign_cancelled'], true) && (int) data_get(json_decode($e->data, true), 'request_id') === $latest->id)->last() : null;
        $approval = $decision?->action === 'foreign_approved' ? json_decode($decision->data, true) : null;
        $sheet = $this->sheet($r, $req['starts_local'] ?? null, $req['ends_local'] ?? null);
        $stale = $req && (! hash_equals($req['context_fingerprint'], hash('sha256', json_encode($this->context($r)))) || ($approval && (int) $approval['circulation_id'] !== (int) ($sheet?->id ?? 0)));
        $allowed = in_array($r->status, ['demande', 'temporaire', 'confirmee', 'en_location'], true);
        $tripEnded = $req && app(RequestPeriod::class)->instant($req['ends_local'], 'ends_local') <= now();
        $state = ! $req ? 'none' : ($stale ? 'stale' : match ($decision?->action) {
            'foreign_approved' => 'approved','foreign_refused' => 'refused','foreign_cancelled' => 'cancelled',default => 'pending'
        });
        $issue = $decision ? $events->filter(fn ($e) => $e->action === 'foreign_authorization_requested' && (int) data_get(json_decode($e->data, true), 'approval_id') === $decision->id)->last() : null;
        $render = $issue ? $events->filter(fn ($e) => in_array($e->action, ['foreign_authorization_generated', 'foreign_authorization_failed'], true) && (int) data_get(json_decode($e->data, true), 'issue_id') === $issue->id)->last() : null;
        $canIssue = $state === 'approved' && ! $tripEnded && in_array($r->status, ['confirmee', 'en_location'], true) && $sheet && $actor->is_primary_responsible;
        $actors = CompanyMembership::whereIn('id', $events->pluck('actor_membership_id'))->with('user')->get()->keyBy('id');

        return ['can_request' => $allowed, 'can_approve' => (bool) $actor->is_primary_responsible, 'can_issue' => (bool) $canIssue, 'state' => $state, 'revision' => $events->max('id'),
            'request' => $latest ? ['id' => $latest->id, 'created_at' => $latest->created_at, ...collect($req)->only(['countries', 'transit_countries', 'starts_local', 'ends_local', 'reason'])->all()] : null,
            'approval' => $approval ? collect($approval)->only(['insurer', 'insurance_reference', 'insurance_starts_on', 'insurance_expires_on', 'reason'])->all() : null,
            'circulation' => $this->circulation($r), 'circulation_covers_trip' => (bool) $sheet,
            'authorization' => $issue ? ['id' => $issue->id, 'reference' => 'AUT-'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT).'-'.$issue->id,
                'status' => match ($render?->action) {
                    'foreign_authorization_generated' => 'ready','foreign_authorization_failed' => 'failed',default => 'pending'
                }, 'download_allowed' => (bool) $canIssue, 'demo_only' => true] : null,
            'history' => $events->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'created_at' => $e->created_at, 'actor' => $actors->get($e->actor_membership_id)?->user?->name, 'reason' => data_get(json_decode($e->data, true), 'reason')])->all()];
    }

    public function save(int $id, CompanyMembership $actor, array $input): array
    {
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $hash): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $action = $input['action'];
            if ($action !== 'request') {
                abort_unless($actor->is_primary_responsible, 403, 'Seul le responsable peut décider ou préparer l’autorisation.');
            }
            $old = DB::table('reservation_events')->where('company_id', Tenant::id())->where('action', 'like', 'foreign_%')->where('data->request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals(data_get(json_decode($old->data, true), 'request_hash', ''), $hash), 409, 'Cette demande a déjà servi pour d’autres informations.');

                return ['replayed' => true];
            }
            $s = $this->summary($r, $actor);
            abort_unless($s['can_request'], 422, 'Ce dossier ne permet plus une demande de sortie.');
            abort_unless((int) ($input['expected_revision'] ?? 0) === (int) ($s['revision'] ?? 0), 409, 'Le suivi a changé. Actualisez avant de recommencer.');
            $data = ['request_key' => $input['request_key'], 'request_hash' => $hash, 'reason' => $input['reason']];
            if ($action === 'request') {
                $start = app(RequestPeriod::class)->instant($input['starts_local'], 'starts_local');
                $end = app(RequestPeriod::class)->instant($input['ends_local'], 'ends_local');
                if ($end <= $start || $start < $r->starts_at || $end > $r->ends_at) {
                    throw ValidationException::withMessages(['ends_local' => 'Le voyage doit être compris dans les dates du dossier, avec un retour après le départ.']);
                }
                if ($end <= now()) {
                    throw ValidationException::withMessages(['ends_local' => 'Le voyage doit être à venir ou en cours.']);
                }
                $data += collect($input)->only(['countries', 'transit_countries', 'starts_local', 'ends_local'])->all();
                $data['context_fingerprint'] = hash('sha256', json_encode($this->context($r)));
                $event = 'foreign_requested';
            } else {
                abort_unless($s['request'], 422, 'Enregistrez d’abord une demande de voyage.');
                $data['request_id'] = $s['request']['id'];
                if ($action === 'approve') {
                    abort_unless($s['state'] === 'pending', 409, 'Cette demande a déjà été décidée ou doit être actualisée.');
                    abort_unless(app(RequestPeriod::class)->instant($s['request']['ends_local'], 'ends_local') > now(), 422, 'La période du voyage est déjà terminée.');
                    $sheet = $this->sheet($r, $s['request']['starts_local'], $s['request']['ends_local']);
                    abort_unless($sheet, 422, 'La feuille de circulation doit couvrir tout le voyage. Vérifiez ses dates dans le fichier véhicule.');
                    if ($input['insurance_starts_on'] > substr($s['request']['starts_local'], 0, 10) || $input['insurance_expires_on'] < substr($s['request']['ends_local'], 0, 10)) {
                        throw ValidationException::withMessages(['insurance_expires_on' => 'La couverture internationale doit couvrir tout le voyage.']);
                    }
                    $data += collect($input)->only(['insurer', 'insurance_reference', 'insurance_starts_on', 'insurance_expires_on'])->all();
                    $data += ['circulation_id' => $sheet->id, 'countries' => $s['request']['countries'], 'transit_countries' => $s['request']['transit_countries'], 'coverage_attested' => true];
                    $event = 'foreign_approved';
                } elseif (in_array($action, ['refuse', 'cancel'], true)) {
                    abort_unless(in_array($s['state'], ['pending', 'approved', 'stale'], true), 409, 'Cette demande est déjà clôturée.');
                    $event = $action === 'refuse' ? 'foreign_refused' : 'foreign_cancelled';
                } elseif ($action === 'issue') {
                    abort_unless($s['can_issue'], 422, 'Accord du responsable, dossier confirmé et couverture valides requis avant l’autorisation.');
                    abort_unless(! $s['authorization'] || $s['authorization']['status'] === 'failed', 409, 'Une préparation existe déjà. Actualisez son état.');
                    abort_unless(config('rental_contracts.demo_only'), 422, 'Le modèle réel doit être validé avant émission.');
                    $approval = $this->events($r->id)->where('action', 'foreign_approved')->where('data->request_id', $s['request']['id'])->latest('id')->firstOrFail();
                    $data['approval_id'] = $approval->id;
                    $data['snapshot'] = ['context' => $this->context($r), 'travel' => $s['request'], 'insurance' => $s['approval'], 'agency' => Company::findOrFail(Tenant::id())->name, 'demo_only' => true];
                    $event = 'foreign_authorization_requested';
                } else {
                    abort(422);
                }
            }
            $eventId = $this->event($id, $actor->id, $event, $data);
            if ($action === 'issue') {
                RenderForeignAuthorization::dispatch(Tenant::id(), $id, $eventId)->onQueue(config('rental_contracts.queue'))->afterCommit();
            }

            return ['replayed' => false];
        });
    }

    public function event(int $reservation, int $actor, string $action, array $data): int
    {
        return DB::table('reservation_events')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'actor_membership_id' => $actor, 'action' => $action, 'data' => json_encode($data), 'created_at' => now()]);
    }
}
