<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceReadiness
{
    public function pending(object $entry, ?array $snapshot = null): array
    {
        $m = app(VehicleMaintenance::class);
        $returned = $snapshot !== null ? ($snapshot['returns'][$entry->id] ?? null) : $m->query('maintenance_returns')->where('maintenance_id', $entry->id)->first();
        $events = $snapshot !== null ? ($snapshot['events'][$entry->id] ?? collect()) : $m->query('maintenance_followups')->where('maintenance_id', $entry->id)->get();
        $checks = $snapshot !== null ? ($snapshot['readiness'][$entry->id] ?? collect()) : $m->query('maintenance_readiness')->where('maintenance_id', $entry->id)->get();
        $pending = [];
        if ($returned && ! $returned->ready && ! $checks->contains(fn ($row) => $row->followup_return_id === null)) {
            $pending[] = ['followup_return_id' => null, 'item_key' => null, 'returned_on' => $returned->returned_on, 'note' => $returned->note];
        }
        foreach ($events as $event) {
            if ($event->action === 'return' && $event->ready === false && ! $checks->contains('followup_return_id', $event->id)) {
                $pending[] = ['followup_return_id' => $event->id, 'item_key' => $event->item_key, 'returned_on' => $event->occurred_on, 'note' => $event->note];
            }
        }

        return $pending;
    }

    public function save(int $vehicle, int $entry, int $actor, array $input): int
    {
        $m = app(VehicleMaintenance::class);
        $hash = $m->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry]);

        return DB::transaction(function () use ($m, $vehicle, $entry, $actor, $input, $hash): int {
            $m->lock($vehicle);
            $e = $m->entry($vehicle, $entry);
            if ($old = $m->replay('maintenance_readiness', $input['request_key'], $hash)) {
                return $old->id;
            }
            $m->assertActive($e);
            abort_if($e->revision !== (int) $input['revision'], 409, 'Les travaux ont changé. Actualisez le dossier avant de valider la disponibilité.');
            $target = collect($this->pending($e))->first(fn ($r) => $r['followup_return_id'] === ($input['followup_return_id'] === null ? null : (int) $input['followup_return_id']));
            abort_unless($target, 409, 'Ce retour ne nécessite plus de contrôle de disponibilité.');
            if ($input['checked_on'] < max($target['returned_on'], collect($e->items)->max('completed_on') ?? $target['returned_on']) || ! trim($input['note'])) {
                throw ValidationException::withMessages(['checked_on' => 'Renseignez le contrôle effectué après le retour et son observation.']);
            }
            foreach ($e->items as $item) {
                if (in_array($item->status, ['pending', 'progress'], true)) {
                    throw ValidationException::withMessages(['ready' => 'Terminez ou reportez les travaux avant de déclarer la voiture prête.']);
                }
            }
            $id = DB::table('maintenance_readiness')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry, 'followup_return_id' => $input['followup_return_id'], 'actor_membership_id' => $actor, 'checked_on' => $input['checked_on'], 'note' => $input['note'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $m->audit($vehicle, $actor, 'maintenance_readiness_checked', ['maintenance_id' => $entry, 'readiness_id' => $id, 'followup_return_id' => $input['followup_return_id'], 'checked_on' => $input['checked_on'], 'note' => $input['note']]);

            return $id;
        });
    }
}
