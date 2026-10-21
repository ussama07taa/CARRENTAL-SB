<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectMaintenancePaymentRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenancePayments;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenancePaymentCorrectionController extends Controller
{
    public function store(CorrectMaintenancePaymentRequest $request, int $vehicle, int $entry, int $payment, VehicleMaintenance $maintenance, MaintenancePayments $payments): JsonResponse
    {
        $actor = $maintenance->actor($request->user()->id);
        $input = $request->validated();
        $hash = $maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry, 'payment' => $payment]);

        return DB::transaction(function () use ($vehicle, $entry, $payment, $maintenance, $payments, $actor, $input, $hash) {
            $maintenance->lock($vehicle);
            $e = $maintenance->entry($vehicle, $entry);
            $p = $payments->query()->where('p.maintenance_id', $entry)->where('p.id', $payment)->firstOrFail();
            if ($old = $maintenance->replay('maintenance_payment_corrections', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id], 201);
            }
            $maintenance->assertActive($e);
            abort_if($p->cancellation_id !== null, 409, 'Ce paiement est annulé et ne peut plus être corrigé.');
            $paid = $maintenance->paid($entry);
            abort_if((int) $p->correction_revision !== (int) $input['correction_revision'] || $e->revision !== (int) $input['work_revision'] || $paid !== (int) $input['expected_paid_centimes'], 409, 'Le paiement ou le dossier a changé. Fermez puis rouvrez le dossier pour vérifier le nouveau calcul.');
            $before = (int) $p->effective_amount_centimes;
            $amount = (int) $input['amount_centimes'];
            $reason = trim($input['reason']);
            if (! $reason) {
                throw ValidationException::withMessages(['reason' => 'Expliquez l’erreur de saisie.']);
            }
            if ($amount < (int) $p->refunded_centimes) {
                throw ValidationException::withMessages(['amount_centimes' => 'Ce paiement a déjà été remboursé en partie. Le montant corrigé ne peut pas être inférieur aux remboursements rattachés.']);
            }
            if ($amount === $before) {
                throw ValidationException::withMessages(['amount_centimes' => 'Le montant est identique au montant déjà enregistré.']);
            }
            $after = $paid - $before + $amount;
            if ($e->cost_final && $amount > $before && $after > $e->cost_centimes) {
                throw ValidationException::withMessages(['amount_centimes' => 'Cette correction dépasserait le coût confirmé du dossier.']);
            }
            $version = (int) ($maintenance->query('maintenance_payment_corrections')->where('payment_key', $p->request_key)->max('version') ?? 0) + 1;
            $id = DB::table('maintenance_payment_corrections')->insertGetId(['company_id' => Tenant::id(), 'payment_key' => $p->request_key, 'actor_membership_id' => $actor, 'version' => $version, 'before_centimes' => $before, 'amount_centimes' => $amount, 'reason' => $reason, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $maintenance->audit($vehicle, $actor, 'maintenance_payment_corrected', ['maintenance_id' => $entry, 'payment_id' => $payment, 'correction_id' => $id, 'before_centimes' => $before, 'amount_centimes' => $amount, 'reason' => $reason]);

            return response()->json(['id' => $id], 201);
        });
    }
}
