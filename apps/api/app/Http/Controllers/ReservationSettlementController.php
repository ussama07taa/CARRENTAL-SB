<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationSettlementController extends Controller
{
    public function show(Request $request, int $reservation, RentalSettlement $settlements): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $settlements): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();

            return response()->json(['data' => $settlements->summary(Reservation::findOrFail($reservation), $actor)]);
        });
    }

    public function store(Request $request, int $reservation, RentalSettlement $settlements): JsonResponse
    {
        $input = $request->validate(['kind' => ['required', Rule::in(['fuel_amount', 'payment_void', 'deposit_return', 'deposit_allocate', 'rent_total', 'refund_approved', 'refund_paid', 'refund_cancelled'])], 'scope' => ['required', Rule::in(['rent', 'fuel', 'deposit', 'damage'])], 'amount_centimes' => ['required_unless:kind,payment_void', 'nullable', 'integer', 'between:0,99999999999'], 'payment_id' => ['nullable', 'integer', 'min:1'], 'deposit_receipt_id' => ['nullable', 'integer', 'min:1'], 'source_id' => ['nullable', 'integer', 'min:1'], 'expected_revision' => ['present', 'nullable', 'integer', 'min:1'], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'method' => ['nullable', Rule::in(['especes', 'virement', 'carte', 'carte_bloquee'])], 'reference' => ['nullable', 'string', 'max:200', 'required_if:method,virement,carte,carte_bloquee'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'checked' => ['required', 'accepted'], 'agreement_confirmed' => ['sometimes', 'boolean'], 'no_funds_received' => ['sometimes', 'boolean'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']]);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $replayed = $settlements->perform($reservation, $actor, $input);

        return response()->json(['data' => $settlements->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
