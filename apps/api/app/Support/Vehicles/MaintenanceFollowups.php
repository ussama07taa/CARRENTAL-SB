<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaintenanceFollowups
{
    public function __construct(private VehicleMaintenance $maintenance, private MaintenanceWork $work) {}

    public function tracking(object $entry, ?array $snapshot = null): array
    {
        $events = $snapshot !== null ? ($snapshot['events'][$entry->id] ?? collect()) : DB::table('maintenance_followups')->where('company_id', Tenant::id())->where('maintenance_id', $entry->id)->orderBy('id')->get();
        $tracking = [];
        foreach ($entry->items as $item) {
            if (! $item->item_key) {
                continue;
            }$rows = $events->where('item_key', $item->item_key);
            $returns = $rows->where('action', 'return')->pluck('visit_id')->all();
            $visit = $rows->where('action', 'start')->first(fn ($v) => ! in_array($v->id, $returns));
            $last = $rows->last();
            $plan = $rows->whereIn('action', ['plan', 'defer'])->last();
            $tracking[$item->item_key] = ['tracking_revision' => $last?->id ?? 0, 'follow_up_on' => $plan?->follow_up_on, 'visit' => $visit ? ['id' => $visit->id, 'started_on' => $visit->occurred_on] : null, 'history' => $rows->map(fn ($v) => ['id' => $v->id, 'action' => $v->action, 'occurred_on' => $v->occurred_on, 'follow_up_on' => $v->follow_up_on, 'note' => $v->note, 'created_at' => $v->created_at])->values()->all()];
        }

        return $tracking;
    }

    public function save(int $vehicle, int $entry, string $itemKey, int $actor, array $input): int
    {
        $hash = $this->maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry, 'item_key' => $itemKey]);

        return DB::transaction(function () use ($vehicle, $entry, $itemKey, $actor, $input, $hash) {
            $v = $this->maintenance->lock($vehicle);
            $e = $this->maintenance->entry($vehicle, $entry);
            $item = collect($e->items)->firstWhere('item_key', $itemKey);
            abort_unless($item, 404);
            if ($old = $this->maintenance->replay('maintenance_followups', $input['request_key'], $hash)) {
                return $old->id;
            }
            $this->maintenance->assertActive($e);
            abort_if($item->cancellation !== null, 409, 'Ce travail est annulé et ne peut plus être repris.');
            $tracking = $this->tracking($e)[$itemKey];
            $visit = $tracking['visit'];
            abort_if($e->revision !== (int) $input['revision'] || $tracking['tracking_revision'] !== (int) $input['tracking_revision'], 409, 'Le suivi a changé. Actualisez le dossier avant de continuer.');
            $action = $input['action'];
            $date = $input['occurred_on'];
            if (! trim($input['note'])) {
                throw ValidationException::withMessages(['note' => 'Ajoutez une observation ou un motif.']);
            }
            $rootReturn = $this->maintenance->query('maintenance_returns')->where('maintenance_id', $entry)->first();
            $originalOpen = $e->in_garage && ! $rootReturn;
            $lastEventDate = collect($tracking['history'])->reject(fn ($event) => $event['action'] === 'plan')->max('occurred_on') ?? $e->performed_on;
            $minDate = max($lastEventDate, $e->performed_on, $rootReturn?->returned_on ?? $e->performed_on, $visit['started_on'] ?? $e->performed_on);
            if ($date < $minDate) {
                throw ValidationException::withMessages(['occurred_on' => 'La date ne peut pas précéder la visite ou le dernier retour enregistré.']);
            }
            if (in_array($action, ['plan', 'start'], true) && ($item->status !== 'deferred' || $visit)) {
                throw ValidationException::withMessages(['action' => 'Ce travail doit être reporté et sans visite en cours.']);
            }
            if ($action === 'start' && $originalOpen) {
                throw ValidationException::withMessages(['action' => 'La voiture est déjà au garage dans le dossier initial. Enregistrez son retour avant une nouvelle visite.']);
            }
            if ($action === 'complete' && ! in_array($item->status, ['deferred', 'progress'], true)) {
                throw ValidationException::withMessages(['action' => 'Ce travail est déjà terminé ou ne fait pas partie des travaux à reprendre.']);
            }
            if ($action === 'complete' && $item->status === 'progress' && ! $visit) {
                throw ValidationException::withMessages(['action' => 'Terminez ce travail depuis son dossier initial.']);
            }
            if ($action === 'return' && (! $visit || ! in_array($item->status, ['done', 'deferred'], true))) {
                throw ValidationException::withMessages(['ready' => 'Terminez le travail ou reportez-le avec un motif avant le retour.']);
            }
            if ($action === 'defer' && (! $visit || $item->status !== 'progress')) {
                throw ValidationException::withMessages(['action' => 'Seul un travail repris et en cours peut être reporté ici.']);
            }
            if (in_array($action, ['start', 'complete', 'defer'], true)) {
                $data = array_intersect_key((array) $item, array_flip(['kind', 'title', 'status', 'cost_status', 'amount_centimes', 'completed_on', 'kilometers', 'next_on', 'next_km', 'reason', 'notes']));
                $data['status'] = match ($action) {
                    'start' => 'progress','complete' => 'done',default => 'deferred'
                };
                $data['reason'] = $input['note'];
                $data['completed_on'] = $action === 'complete' ? $date : null;
                $data['kilometers'] = $action === 'complete' ? ($input['kilometers'] ?? null) : null;
                $data['next_on'] = $action === 'complete' ? ($input['next_on'] ?? null) : null;
                $data['next_km'] = $action === 'complete' ? ($input['next_km'] ?? null) : null;
                $data = $this->work->validateItem($data, $e);
                $workId = $this->work->append($e, $data, $actor, $itemKey, $item->version + 1, (string) Str::uuid(), $hash);
                if ($action === 'complete') {
                    $this->maintenance->recordReading($v, isset($input['kilometers']) ? (int) $input['kilometers'] : null, $date, $actor);
                }
            } else {
                $workId = $item->id;
            }
            $id = DB::table('maintenance_followups')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry, 'work_id' => $workId, 'item_key' => $itemKey, 'actor_membership_id' => $actor, 'action' => $action, 'occurred_on' => $date, 'follow_up_on' => in_array($action, ['plan', 'defer'], true) ? ($input['follow_up_on'] ?? null) : null, 'visit_id' => $visit['id'] ?? null, 'note' => $input['note'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $this->maintenance->audit($vehicle, $actor, 'maintenance_followup_'.$action, ['maintenance_id' => $entry, 'item_key' => $itemKey, 'followup_id' => $id, 'note' => $input['note']]);

            return $id;
        });
    }
}
