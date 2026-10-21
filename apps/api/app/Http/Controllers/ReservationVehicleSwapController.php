<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalVehicleSwap;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationVehicleSwapController extends Controller
{
    public function show(Request $request, int $reservation, RentalVehicleSwap $swaps): JsonResponse
    {
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => $swaps->summary(Reservation::findOrFail($reservation), $actor)]);
    }

    public function store(Request $request, int $reservation, RentalVehicleSwap $swaps): JsonResponse
    {
        $input = $request->validate(['vehicle_id' => ['required', 'integer', 'min:1'], 'return_id' => ['required', 'integer', 'min:1'], 'inspection_id' => ['required', 'integer', 'min:1'], 'expected_swap_id' => ['present', 'nullable', 'integer', 'min:1'], 'expected_total_centimes' => ['required', 'integer', 'between:0,99999999999'], 'assigned_local' => ['required', 'date_format:Y-m-d\TH:i'], 'difference_centimes' => ['required', 'integer', 'between:-99999999999,99999999999'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'agreement_confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'blocked_until' => ['prohibited']]);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        try {
            $replayed = $swaps->prepare($reservation, $actor, $input);
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'Cette voiture vient d’être réservée. Le retour, les documents et les paiements restent conservés.'], 409);
            }
            throw $e;
        }

        return response()->json(['data' => $swaps->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
