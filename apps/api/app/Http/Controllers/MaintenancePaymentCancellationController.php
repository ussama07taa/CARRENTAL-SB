<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelMaintenancePaymentRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenancePayments;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenancePaymentCancellationController extends Controller
{
    public function store(CancelMaintenancePaymentRequest $request, int $vehicle, int $entry, int $payment, VehicleMaintenance $maintenance, MaintenancePayments $payments): JsonResponse
    {
        $actor = $maintenance->actor($request->user()->id);
        $input = $request->validated();
        $hash = $maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry, 'payment' => $payment]);

        return DB::transaction(function () use ($vehicle, $entry, $payment, $maintenance, $payments, $actor, $input, $hash) {
            $maintenance->lock($vehicle);
            $e = $maintenance->entry($vehicle, $entry);
            $p = $payments->query()->where('p.maintenance_id', $entry)->where('p.id', $payment)->firstOrFail();
            if ($old = $maintenance->replay('maintenance_payment_cancellations', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id], 201);
            }
            $maintenance->assertActive($e);
            abort_if($p->cancellation_id !== null, 409, 'Ce paiement est déjà annulé. Actualisez le dossier pour consulter son historique.');
            abort_if((int) $p->refunded_centimes > 0, 422, 'Ce paiement est lié à un remboursement réel et ne peut pas être annulé comme une erreur de saisie.');
            $paid = $maintenance->paid($entry);
            abort_if((int) $p->correction_revision !== (int) $input['correction_revision'] || $e->revision !== (int) $input['work_revision'] || $paid !== (int) $input['expected_paid_centimes'], 409, 'Le paiement ou le dossier a changé. Fermez puis rouvrez le dossier pour vérifier le nouveau calcul.');
            $reason = trim($input['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Expliquez pourquoi ce paiement a été enregistré par erreur.']);
            }
            $before = (int) $p->effective_amount_centimes;
            $id = DB::table('maintenance_payment_cancellations')->insertGetId(['company_id' => Tenant::id(), 'payment_key' => $p->request_key, 'actor_membership_id' => $actor, 'before_centimes' => $before, 'reason' => $reason, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $maintenance->audit($vehicle, $actor, 'maintenance_payment_cancelled', ['maintenance_id' => $entry, 'payment_id' => $payment, 'cancellation_id' => $id, 'before_centimes' => $before, 'reason' => $reason]);

            return response()->json(['id' => $id], 201);
        });
    }
}
