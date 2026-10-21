<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelMaintenanceRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenanceFollowups;
use App\Support\Vehicles\MaintenanceWork;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceCancellationController extends Controller
{
    public function store(CancelMaintenanceRequest $request, int $vehicle, int $entry, VehicleMaintenance $maintenance, MaintenanceWork $work, MaintenanceFollowups $followups): JsonResponse
    {
        $actor = $maintenance->actor($request->user()->id);
        $input = $request->validated();
        $hash = $maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry]);

        return DB::transaction(function () use ($vehicle, $entry, $maintenance, $work, $followups, $actor, $input, $hash) {
            $maintenance->lock($vehicle);
            $e = $maintenance->entry($vehicle, $entry);
            if ($old = $maintenance->replay('maintenance_cancellations', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id], 201);
            }
            $maintenance->assertActive($e);
            $paid = $maintenance->paid($entry);
            abort_if($e->revision !== (int) $input['revision'] || $e->cancellation_revision !== (int) $input['cancellation_revision'] || $paid !== (int) $input['expected_paid_centimes'], 409, 'Le dossier a changé. Fermez puis rouvrez-le pour vérifier le calcul avant l’annulation.');
            $reason = trim($input['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Expliquez l’erreur de saisie à annuler.']);
            }
            $tracking = $followups->tracking($e);
            $item = null;
            if ($input['scope'] === 'work') {
                $item = collect($e->items)->firstWhere('item_key', $input['item_key']);
                abort_unless($item, 404);
                abort_if($item->cancellation !== null, 409, 'Ce travail est déjà annulé.');
                abort_if($tracking[$item->item_key ?? '']['visit'] ?? null, 422, 'Cette reprise est encore au garage. Enregistrez le retour réel avant d’annuler ce travail.');
                $remaining = $e->cost_centimes - (int) ($item->amount_centimes ?? 0);
                abort_if($paid > $remaining, 422, 'Annulation bloquée : les paiements conservés dépasseraient le coût restant. Vérifiez les paiements et réglez leur situation avant de continuer.');
                if ($item->legacy ?? false) {
                    $work->preserveLegacy($e, $actor);
                    $e = $maintenance->entry($vehicle, $entry);
                    $item = $e->items[0];
                }
            } else {
                abort_if($input['item_key'] !== null, 422, 'Pour annuler le dossier, ne sélectionnez pas un travail.');
                abort_if($paid !== 0, 422, 'Annulation bloquée : ce dossier contient encore des paiements comptabilisés. Ne les annulez que s’il s’agit réellement d’erreurs de saisie.');
                $initialOpen = $e->in_garage && ! $maintenance->query('maintenance_returns')->where('maintenance_id', $entry)->exists();
                abort_if($initialOpen || collect($tracking)->contains(fn ($t) => $t['visit'] !== null), 422, 'Une visite au garage est encore ouverte. Enregistrez le retour réel avant d’annuler le dossier.');
            }
            $target = $item?->item_key ?? 'file';
            $before = $item ? $item->amount_centimes : $e->cost_centimes;
            $id = DB::table('maintenance_cancellations')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry, 'work_id' => $item?->id, 'target' => $target, 'actor_membership_id' => $actor, 'before_centimes' => $before, 'reason' => $reason, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $maintenance->audit($vehicle, $actor, $item ? 'maintenance_work_cancelled' : 'maintenance_file_cancelled', ['maintenance_id' => $entry, 'item_key' => $item?->item_key, 'before_centimes' => $before, 'paid_centimes' => $paid, 'reason' => $reason, 'cancellation_id' => $id]);

            return response()->json(['id' => $id], 201);
        });
    }
}
