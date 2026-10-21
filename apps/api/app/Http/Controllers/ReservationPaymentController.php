<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalPayment;
use App\Support\Reservations\VehiclePricing;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationPaymentController extends Controller
{
    public function calculation(int $reservation, VehiclePricing $pricing, RentalPayment $payments): JsonResponse
    {
        return DB::transaction(function () use ($reservation, $pricing, $payments): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $record = Reservation::findOrFail($reservation);
            if ($record->confirmed_at !== null) {
                return response()->json(['data' => $payments->summary($record)['pricing_snapshot'], 'frozen' => true]);
            }

            return response()->json(['data' => $pricing->forReservation($record), 'frozen' => false]);
        });
    }

    public function quote(Request $request, int $reservation): JsonResponse
    {
        return $this->save($request, $reservation, 'quote', ['total_centimes' => ['required', 'integer', 'min:1', 'max:99999999999'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'expected_quote_id' => ['nullable', 'integer', 'min:1'], 'pricing_version_id' => ['nullable', 'required_if:use_calculated_price,true', 'integer', 'min:1'], 'use_calculated_price' => ['sometimes', 'boolean'], 'agreement_confirmed' => ['required', 'accepted']]);
    }

    public function payment(Request $request, int $reservation): JsonResponse
    {
        return $this->save($request, $reservation, 'payment', ['distinct_payment_confirmed' => ['sometimes', 'boolean'], 'similar_payment_ids' => ['sometimes', 'array', 'max:100'], 'similar_payment_ids.*' => ['integer', 'min:1', 'distinct'], 'amount_centimes' => ['required', 'integer', 'min:1', 'max:99999999999'], 'method' => ['required', Rule::in(['especes', 'virement', 'carte'])], 'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:200', 'required_if:method,virement,carte'], 'note' => ['nullable', 'string', 'max:1000'], 'receipt_attested' => ['required', 'accepted']]);
    }

    public function verify(Request $request, int $reservation, int $payment): JsonResponse
    {
        return $this->save($request, $reservation, 'verify', ['reason' => ['required', 'string', 'min:3', 'max:1000'], 'receipt_attested' => ['required', 'accepted']], $payment);
    }

    public function confirm(Request $request, int $reservation): JsonResponse
    {
        return $this->save($request, $reservation, 'confirm', ['quote_id' => ['required', 'integer', 'min:1'], 'agreement_confirmed' => ['required', 'accepted']]);
    }

    private function save(Request $request, int $id, string $operation, array $rules, ?int $payment = null): JsonResponse
    {
        $input = $request->validate([...$rules, 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'verified' => ['prohibited'], 'actor_membership_id' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un montant en centimes entiers.', 'min' => 'La valeur saisie est insuffisante.', 'max' => 'La valeur saisie dépasse la limite autorisée.', 'accepted' => 'Confirmez cette déclaration avant d’enregistrer.', 'date_format' => 'Saisissez une date valide.', 'before_or_equal' => 'La date ne peut pas être dans le futur.', 'required_if' => 'La référence est obligatoire pour ce mode de paiement.', 'prohibited' => 'Ce champ ne peut pas être fourni.', 'uuid' => 'L’identifiant de cette opération est invalide.']);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $service = app(RentalPayment::class);
        try {
            $result = $service->perform($id, $actor, $operation, $input, $payment);
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'La voiture vient d’être réservée. Les paiements restent conservés.'], 409);
            }
            throw $e;
        }
        if (isset($result['error'])) {
            return response()->json(['code' => $result['error'], 'message' => $result['message'], ...($result['error'] === 'SIMILAR_PAYMENT' ? ['similar_payment_ids' => $result['similar_payment_ids'], 'financial' => $service->summary(Reservation::findOrFail($id))] : [])], $result['status']);
        }

        return response()->json(['financial' => $service->summary(Reservation::findOrFail($id)), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }
}
