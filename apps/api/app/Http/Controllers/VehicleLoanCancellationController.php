<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelVehicleLoanRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleLoans;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleLoanCancellationController extends Controller
{
    public function store(CancelVehicleLoanRequest $request, int $loan, VehicleLoans $loans): JsonResponse
    {
        $actor = $loans->actor($request->user()->id);
        $input = $request->validated();
        $input['expected_loan_remaining_centimes'] = (int) $input['expected_loan_remaining_centimes'];
        $hash = $loans->hash([...$input, 'loan_id' => $loan]);

        return DB::transaction(function () use ($loan, $loans, $actor, $input, $hash) {
            $loans->lock();
            $entry = $loans->loan($loan);
            if ($old = $loans->replay('vehicle_loan_cancellations', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id], 201);
            }
            $loans->ensureActive($loan);
            $summary = $loans->summaries()->where('l.id', $loan)->firstOrFail();
            abort_if(! hash_equals($loans->financialRevision($loan), $input['financial_revision']) || (int) $summary->remaining_centimes !== $input['expected_loan_remaining_centimes'], 409, 'Le dossier a changé. Actualisez les montants avant de confirmer l’annulation.');
            if ((int) $summary->opening_paid_centimes > 0) {
                throw ValidationException::withMessages(['loan' => 'Annulation bloquée : des montants déjà réglés avant le suivi sont enregistrés dans ce contrat. Ils doivent être vérifiés et régularisés séparément, sans effacer l’historique.']);
            }
            if ((int) $summary->recorded_paid_centimes > 0) {
                throw ValidationException::withMessages(['loan' => 'Annulation bloquée : ce contrat contient des paiements encore comptabilisés. Annulez uniquement les saisies erronées ; un paiement réellement effectué doit rester enregistré.']);
            }
            $reason = trim($input['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Expliquez pourquoi ce contrat a été enregistré par erreur.']);
            }
            $id = DB::table('vehicle_loan_cancellations')->insertGetId(['company_id' => Tenant::id(), 'loan_id' => $loan, 'actor_membership_id' => $actor, 'before_remaining_centimes' => (int) $summary->remaining_centimes, 'reason' => $reason, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $loans->audit($entry, $actor, 'loan_cancelled', ['cancellation_id' => $id, 'before_remaining_centimes' => (int) $summary->remaining_centimes, 'reason' => $reason]);

            return response()->json(['id' => $id], 201);
        });
    }
}
