<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelMaintenanceRefundRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenancePayments;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceRefundCancellationController extends Controller
{
    public function store(CancelMaintenanceRefundRequest $request, int $vehicle, int $entry, int $refund, VehicleMaintenance $maintenance, MaintenancePayments $payments): JsonResponse
    {
        $actor = $maintenance->actor($request->user()->id);
        $input = $request->validated();
        foreach (['work_revision', 'cancellation_revision', 'expected_paid_centimes', 'expected_refunded_centimes'] as $name) {
            $input[$name] = (int) $input[$name];
        }
        $hash = $maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry, 'refund' => $refund]);

        return DB::transaction(function () use ($vehicle, $entry, $refund, $maintenance, $payments, $actor, $input, $hash) {
            $maintenance->lock($vehicle);
            $e = $maintenance->entry($vehicle, $entry);
            $r = $maintenance->query('maintenance_refunds')->where('maintenance_id', $entry)->where('id', $refund)->firstOrFail();
            if ($old = $maintenance->replay('maintenance_refund_cancellations', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id], 201);
            }
            $maintenance->assertActive($e);
            abort_if($maintenance->query('maintenance_refund_cancellations')->where('refund_id', $refund)->exists(), 409, 'Ce remboursement est déjà annulé. Actualisez le dossier pour consulter son historique.');
            $cash = $payments->cash([$entry])[$entry] ?? null;
            $net = (int) ($cash->net_centimes ?? 0);
            $refunded = (int) ($cash->refunded_centimes ?? 0);
            abort_if($e->revision !== $input['work_revision'] || $e->cancellation_revision !== $input['cancellation_revision'] || $net !== $input['expected_paid_centimes'] || $refunded !== $input['expected_refunded_centimes'], 409, 'Le dossier ou ses montants ont changé. Fermez puis rouvrez le dossier pour vérifier le nouveau calcul.');
            $reason = trim($input['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Expliquez pourquoi ce remboursement a été enregistré par erreur.']);
            }
            $id = DB::table('maintenance_refund_cancellations')->insertGetId(['company_id' => Tenant::id(), 'refund_id' => $refund, 'actor_membership_id' => $actor, 'before_centimes' => (int) $r->amount_centimes, 'reason' => $reason, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $maintenance->audit($vehicle, $actor, 'maintenance_refund_cancelled', ['maintenance_id' => $entry, 'refund_id' => $refund, 'cancellation_id' => $id, 'before_centimes' => (int) $r->amount_centimes, 'reason' => $reason]);

            return response()->json(['id' => $id], 201);
        });
    }
}
