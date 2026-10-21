<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalCancellation;
use App\Support\Reservations\RentalSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationCancellationController extends Controller
{
    public function store(Request $request, int $reservation, RentalCancellation $cancellations, RentalSettlement $settlements): JsonResponse
    {
        $input = $request->validate(['final_total_centimes' => ['required', 'integer', 'between:0,99999999999'], 'expected_revision' => ['present', 'nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'agreement_confirmed' => ['required', 'accepted'], 'no_vehicle_handed_over' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $replayed = $cancellations->perform($reservation, $actor, $input);

        return response()->json(['data' => $settlements->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
