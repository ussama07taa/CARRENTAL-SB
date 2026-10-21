<?php

namespace App\Support\Vehicles;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\RequestAvailability;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DamageTickets
{
    public function query(string $table = 'damage_tickets')
    {
        return DB::table($table)->where('company_id', Tenant::id());
    }

    public function blockers(): array
    {
        if (! Schema::hasTable('damage_tickets')) {
            return [];
        }

        return $this->query()->where('closed', false)->whereIn('mobility', ['review', 'blocked'])->distinct()->pluck('vehicle_id')->map(fn ($id) => (int) $id)->all();
    }

    // Ticket corrections are immutable replacement decisions, never additional charges.
    public static function approvedDamageTotal($rows): int
    {
        $legacy = 0;
        $tickets = [];
        foreach ($rows->where('kind', 'damage_amount')->sortBy('id') as $row) {
            $snapshot = json_decode($row->snapshot, true);
            if (isset($snapshot['damage_ticket_id'])) {
                $tickets[$snapshot['damage_ticket_id']] = (int) $row->amount_centimes;
            } else {
                $legacy += (int) $row->amount_centimes;
            }
        }

        return $legacy + array_sum($tickets);
    }

    public function inspection(int $id): object
    {
        $inspection = $this->query('rental_return_inspections')->where('id', $id)->firstOrFail();
        $return = $this->query('rental_returns')->where('id', $inspection->return_id)->firstOrFail();

        return (object) [...(array) $inspection, 'vehicle_id' => $return->vehicle_id];
    }

    private function addObservation(int $ticket, int $vehicle, int $inspectionId, string $kind): void
    {
        $inspection = $this->inspection($inspectionId);
        abort_unless((int) $inspection->vehicle_id === $vehicle, 422, 'Ce contrôle concerne une autre voiture.');
        abort_if($this->query('damage_observations')->where('ticket_id', $ticket)->where('inspection_id', $inspectionId)->exists(), 409, 'Ce contrôle est déjà lié au dommage.');
        DB::table('damage_observations')->insert(['company_id' => Tenant::id(), 'ticket_id' => $ticket, 'inspection_id' => $inspectionId, 'kind' => $kind, 'created_at' => now()]);
    }

    public function save(CompanyMembership $actor, ?int $id, array $input): int
    {
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($actor, $id, $input, $hash) {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $kind = $input['kind'];
            if (in_array($kind, ['decision', 'insurance', 'receipt', 'receipt_void', 'maintenance', 'close'], true) || ($kind === 'followup' && (isset($input['mobility']) || isset($input['repair'])))) {
                abort_unless($actor->is_primary_responsible, 403, 'Cette décision est réservée au responsable.');
            }
            $old = $this->query('damage_entries')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'La clé a déjà été utilisée avec des données différentes.');

                return (int) $old->ticket_id;
            }
            $data = $input;
            unset($data['request_key'],$data['expected_version'],$data['reason'],$data['kind']);
            if ($id === null) {
                abort_unless($kind === 'create', 422, 'Action invalide.');
                $v = Vehicle::whereKey($input['vehicle_id'])->lockForUpdate()->firstOrFail();
                CompanyMembership::findOrFail($input['owner_membership_id']);
                $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['occurred_local'], 'Africa/Casablanca');
                abort_if($at->isFuture(), 422, 'Le moment constaté ne peut pas être futur.');
                if (! empty($input['inspection_id'])) {
                    $inspection = $this->inspection($input['inspection_id']);
                    abort_unless((int) $inspection->vehicle_id === $v->id, 422, 'Le contrôle concerne une autre voiture.');
                }
                $id = DB::table('damage_tickets')->insertGetId(['company_id' => Tenant::id(), 'vehicle_id' => $v->id, 'owner_membership_id' => $input['owner_membership_id'], 'title' => $input['title'], 'zone' => $input['zone'], 'description' => $input['description'], 'occurred_at' => $at, 'follow_up_on' => $input['follow_up_on'], 'next_action' => $input['next_action'], 'created_at' => now(), 'updated_at' => now()]);
                if (! empty($input['inspection_id'])) {
                    $this->addObservation($id, $v->id, $input['inspection_id'], 'new');
                }
                // Any newly reported damage needs an explicit operational review before another pickup.
                $v->version++;
                $v->save();
            } else {
                $ticket = $this->query()->where('id', $id)->lockForUpdate()->firstOrFail();
                $vehicle = Vehicle::whereKey($ticket->vehicle_id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $ticket->version === (int) $input['expected_version'], 409, 'Le dossier a changé. Actualisez avant de réessayer.');
                abort_if($ticket->closed && $kind !== 'reopen', 409, 'Rouvrez le dossier avant de le modifier.');
                $changes = [];
                if ($kind === 'followup') {
                    CompanyMembership::findOrFail($input['owner_membership_id']);
                    $changes = array_intersect_key($input, array_flip(['owner_membership_id', 'follow_up_on', 'next_action', 'mobility', 'repair']));
                    if (($input['mobility'] ?? null) === 'usable') {
                        abort_unless($input['checked'] ?? false, 422, 'Confirmez le contrôle de sécurité.');
                        abort_if(in_array((int) $ticket->vehicle_id, app(RequestAvailability::class)->garageVehicles(), true), 409, 'Un passage au garage est encore ouvert.');
                    }
                    abort_if(($changes['repair'] ?? $ticket->repair) === 'garage' && ($changes['mobility'] ?? $ticket->mobility) === 'usable', 422, 'Une voiture au garage ne peut pas être déclarée utilisable.');
                    if (isset($changes['mobility'])) {
                        $vehicle->version++;
                        $vehicle->save();
                    }
                } elseif ($kind === 'observe') {
                    $this->addObservation($id, (int) $ticket->vehicle_id, $input['inspection_id'], $input['observation_kind']);
                    if ($input['observation_kind'] === 'worsened') {
                        $changes = ['mobility' => 'review', 'repair' => 'review'];
                        $vehicle->version++;
                        $vehicle->save();
                    }
                } elseif ($kind === 'maintenance') {
                    $maintenance = $this->query('vehicle_maintenance')->where('id', $input['maintenance_id'])->where('vehicle_id', $ticket->vehicle_id)->firstOrFail();
                    abort_if($this->query('maintenance_cancellations')->where('maintenance_id', $maintenance->id)->where('target', 'file')->exists(), 422, 'Cette intervention est annulée.');
                    abort_if($this->query('damage_maintenance_links')->where('ticket_id', $id)->where('maintenance_id', $maintenance->id)->exists(), 409, 'Cette intervention est déjà liée.');
                    DB::table('damage_maintenance_links')->insert(['company_id' => Tenant::id(), 'ticket_id' => $id, 'maintenance_id' => $maintenance->id, 'created_at' => now()]);
                } elseif (in_array($kind, ['decision', 'insurance', 'receipt', 'receipt_void', 'close'], true)) {
                    $financial = $this->financial($id);
                    if ($kind === 'decision') {
                        abort_unless($this->query('damage_observations')->where('ticket_id', $id)->exists(), 422, 'Liez le contrôle et ses preuves avant la décision client.');
                        $inspectionIds = $this->query('damage_observations')->where('ticket_id', $id)->pluck('inspection_id');
                        $related = $this->query('rental_return_inspections')->whereIn('id', $inspectionIds)->where('reservation_id', $input['reservation_id'])->pluck('id');
                        abort_if($related->isEmpty(), 422, 'La décision doit désigner une location liée à ce dommage.');
                        if (($input['amount_centimes'] ?? 0) > 0) {
                            abort_unless($this->query('rental_return_files')->whereIn('inspection_id', $related)->where('kind', 'photo')->exists(), 422, 'Des photos du contrôle lié sont nécessaires avant une décision financière.');
                        }
                        abort_unless($input['checked'] ?? false, 422, 'Confirmez la décision et ses justificatifs.');
                        $previous = $financial['decision'];
                        abort_if($previous && (int) $previous['reservation_id'] !== (int) $input['reservation_id'], 422, 'La décision précédente concerne une autre location. Faites examiner cette attribution avant une nouvelle décision.');
                        abort_if($previous && $previous['amount_centimes'] !== null && $input['amount_centimes'] === null, 422, 'Une décision financière existante nécessite un montant de correction explicite.');
                        if ($input['amount_centimes'] !== null) {
                            $reservation = Reservation::whereKey($input['reservation_id'])->lockForUpdate()->firstOrFail();
                            $rows = $this->query('rental_settlement_entries')->where('reservation_id', $reservation->id)->get();
                            $revised = self::approvedDamageTotal($rows) - (int) ($previous['amount_centimes'] ?? 0) + (int) $input['amount_centimes'];
                            $allocated = (int) $rows->where('kind', 'deposit_allocate')->where('scope', 'damage')->sum('amount_centimes');
                            abort_if($revised < $allocated, 422, 'Des fonds ont déjà été affectés. Une restitution doit être examinée avant de réduire cette décision.');
                            DB::table('rental_settlement_entries')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation->id, 'actor_membership_id' => $actor->id, 'kind' => 'damage_amount', 'scope' => 'damage', 'amount_centimes' => $input['amount_centimes'], 'occurred_on' => now('Africa/Casablanca')->toDateString(), 'reference' => 'DOM-'.$id, 'reason' => $input['reason'], 'snapshot' => json_encode(['damage_ticket_id' => $id, 'evidence_reference' => $input['evidence_reference'], 'previous_amount_centimes' => $previous['amount_centimes'] ?? null]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                        }
                    }
                    if ($kind === 'insurance') {
                        abort_if($input['approved_centimes'] !== null && $input['approved_centimes'] < $financial['received_centimes'], 422, 'La décision serait inférieure aux fonds reçus. Vérifiez les encaissements avant correction.');
                        abort_if($input['approved_centimes'] !== null && $input['approved_centimes'] > $input['requested_centimes'], 422, 'Le montant approuvé dépasse la demande.');
                    }
                    if ($kind === 'receipt') {
                        abort_unless($financial['insurance'] && $financial['insurance']['approved_centimes'] !== null, 422, 'Enregistrez le montant approuvé avant le versement.');
                        abort_if($financial['insurance']['approved_centimes'] < $financial['received_centimes'] + $input['amount_centimes'], 422, 'Le versement dépasse le restant approuvé.');
                        abort_unless($input['checked'] ?? false, 422, 'Confirmez les fonds réellement reçus.');
                    }
                    if ($kind === 'receipt_void') {
                        abort_unless(collect($financial['receipts'])->contains(fn ($entry) => $entry['id'] === $input['entry_id'] && ! $entry['voided']), 422, 'Versement inconnu ou déjà annulé.');
                    }
                    if ($kind === 'close') {
                        abort_unless($ticket->mobility === 'usable' && $ticket->repair === 'done', 422, 'Terminez le suivi opérationnel avant clôture.');
                        abort_if($financial['insurance'] && ($financial['insurance']['approved_centimes'] === null || $financial['received_centimes'] !== $financial['insurance']['approved_centimes']), 422, 'La demande d’assurance reste à terminer.');
                        if ($this->query('damage_observations')->where('ticket_id', $id)->exists()) {
                            abort_unless($financial['decision'] && $financial['decision']['amount_centimes'] !== null, 422, 'Terminez la décision client avant clôture.');
                            $rows = $this->query('rental_settlement_entries')->where('reservation_id', $financial['decision']['reservation_id'])->get();
                            abort_if(self::approvedDamageTotal($rows) > (int) $rows->where('kind', 'deposit_allocate')->where('scope', 'damage')->sum('amount_centimes'), 422, 'Un montant de dommage reste à régler dans la location.');
                        }
                        abort_unless($input['checked'] ?? false, 422, 'Vérifiez le règlement du client dans le dossier de location avant de clôturer.');
                        $changes['closed'] = true;
                    }
                } elseif ($kind === 'reopen') {
                    $changes['closed'] = false;
                } else {
                    abort(422, 'Action invalide.');
                }
                $this->query()->where('id', $id)->update([...$changes, 'version' => $ticket->version + 1, 'updated_at' => now()]);
            }
            DB::table('damage_entries')->insert(['company_id' => Tenant::id(), 'ticket_id' => $id, 'actor_membership_id' => $actor->id, 'kind' => $kind, 'reason' => $input['reason'], 'payload' => json_encode($data), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);

            return $id;
        });
    }

    public function financial(int $id): array
    {
        $rows = $this->query('damage_entries')->where('ticket_id', $id)->orderBy('id')->get();
        $decision = null;
        $insurance = null;
        $receipts = [];
        $voids = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if ($row->kind === 'decision') {
                $decision = $payload;
            }
            if ($row->kind === 'insurance') {
                $insurance = $payload;
            }
            if ($row->kind === 'receipt') {
                $receipts[] = ['id' => $row->id, ...$payload, 'voided' => false];
            }
            if ($row->kind === 'receipt_void') {
                $voids[] = $payload['entry_id'];
            }
        }
        $total = 0;
        foreach ($receipts as &$receipt) {
            $receipt['voided'] = in_array($receipt['id'], $voids, true);
            if (! $receipt['voided']) {
                $total += $receipt['amount_centimes'];
            }
        }

        return ['decision' => $decision, 'insurance' => $insurance, 'receipts' => $receipts, 'received_centimes' => $total];
    }

    public function show(int $id, CompanyMembership $actor): array
    {
        $ticket = $this->query()->where('id', $id)->firstOrFail();
        $vehicle = Vehicle::findOrFail($ticket->vehicle_id);
        $observations = $this->query('damage_observations')->where('ticket_id', $id)->orderBy('id')->get()->map(function ($o) {
            $i = $this->inspection($o->inspection_id);
            $files = $this->query('rental_return_files')->where('inspection_id', $i->id)->whereIn('kind', ['photo', 'video'])->get(['id', 'kind', 'caption']);

            return ['inspection_id' => $i->id, 'reservation_id' => $i->reservation_id, 'return_id' => $i->return_id, 'kind' => $o->kind, 'notes' => $i->notes, 'files' => $files];
        });
        $history = $this->query('damage_entries')->where('ticket_id', $id)->when(! $actor->is_primary_responsible, fn ($q) => $q->whereNotIn('kind', ['decision', 'insurance', 'receipt', 'receipt_void']))->orderByDesc('id')->limit(100)->get()->map(function ($e) {
            $actor = CompanyMembership::find($e->actor_membership_id);

            return ['id' => $e->id, 'kind' => $e->kind, 'reason' => $e->reason, 'at' => $e->created_at, 'actor' => $actor?->user?->name, 'payload' => json_decode($e->payload, true)];
        });
        $available = $actor->is_primary_responsible ? $this->query('vehicle_maintenance')->where('vehicle_id', $ticket->vehicle_id)->whereNotIn('id', $this->query('maintenance_cancellations')->where('target','file')->select('maintenance_id'))->orderByDesc('id')->limit(100)->get(['id', 'title', 'performed_on']) : [];

        return ['ticket' => $ticket, 'vehicle' => $vehicle->only(['id', 'make', 'model', 'plate', 'current_odometer', 'current_odometer_date']), 'observations' => $observations, 'history' => $history, 'available_maintenance' => $available, 'maintenance_ids' => $this->query('damage_maintenance_links')->where('ticket_id',$id)->pluck('maintenance_id'), 'can_decide' => $actor->is_primary_responsible, 'financial' => $actor->is_primary_responsible ? $this->financial($id) : null];
    }
}
