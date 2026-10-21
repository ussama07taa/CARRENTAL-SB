<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectVehicleLoanPaymentRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleLoans;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleLoanPaymentCorrectionController extends Controller
{
    public function store(CorrectVehicleLoanPaymentRequest $request, int $loan, int $payment, VehicleLoans $loans): JsonResponse
    {
        $actor = $loans->actor($request->user()->id);
        $input = $request->validated();
        foreach (['amount_centimes', 'correction_revision', 'expected_remaining_centimes', 'expected_loan_remaining_centimes'] as $field) {
            $input[$field] = (int) $input[$field];
        }
        $hash = $loans->hash([...$input, 'loan_id' => $loan, 'payment_id' => $payment]);

        return DB::transaction(function () use ($loan, $payment, $loans, $actor, $input, $hash) {
            $loans->lock();
            $entry = $loans->loan($loan);
            $loans->ensureActive($loan);
            $p = $loans->payments()->where('p.loan_id', $loan)->where('p.id', $payment)->select('p.*')->selectRaw('COALESCE(c.amount_centimes,p.amount_centimes) AS effective_amount_centimes, COALESCE(c.version,0) AS correction_revision, x.id AS cancellation_id')->firstOrFail();
            if ($old = $loans->replay('vehicle_loan_payment_corrections', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id], 201);
            }
            abort_if($p->cancellation_id !== null, 409, 'Cet enregistrement est annulé et ne peut plus être corrigé.');
            $installment = $loans->installments()->where('i.id', $p->installment_id)->firstOrFail();
            $summary = $loans->summaries()->where('l.id', $loan)->firstOrFail();
            abort_if(! hash_equals($loans->financialRevision($loan), $input['financial_revision']) || (int) $p->correction_revision !== $input['correction_revision'] || (int) $installment->remaining_centimes !== $input['expected_remaining_centimes'] || (int) $summary->remaining_centimes !== $input['expected_loan_remaining_centimes'], 409, 'Le paiement ou le solde du crédit a changé. Actualisez le dossier et vérifiez le nouveau calcul avant de corriger.');
            $before = (int) $p->effective_amount_centimes;
            $amount = $input['amount_centimes'];
            $reason = trim($input['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Expliquez l’erreur de saisie.']);
            }
            if ($amount === $before) {
                throw ValidationException::withMessages(['amount_centimes' => 'Ce montant est identique au montant actuellement retenu.']);
            }
            if ($amount > $before + (int) $installment->remaining_centimes) {
                throw ValidationException::withMessages(['amount_centimes' => 'Le montant corrigé dépasserait le solde disponible de cette échéance, après prise en compte des autres règlements.']);
            }
            $id = DB::table('vehicle_loan_payment_corrections')->insertGetId(['company_id' => Tenant::id(), 'payment_key' => $p->request_key, 'actor_membership_id' => $actor, 'version' => (int) $p->correction_revision + 1, 'before_centimes' => $before, 'amount_centimes' => $amount, 'reason' => $reason, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $loans->audit($entry, $actor, 'loan_payment_corrected', ['payment_id' => $payment, 'installment_id' => $p->installment_id, 'correction_id' => $id, 'before_centimes' => $before, 'amount_centimes' => $amount, 'reason' => $reason]);

            return response()->json(['id' => $id], 201);
        });
    }
}
