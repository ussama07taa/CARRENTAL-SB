<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalPickup;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationPickupController extends Controller
{
    public function show(Request $request, int $reservation, RentalPickup $pickups): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $pickups): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }$actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();

            return response()->json(['data' => $pickups->summary(Reservation::findOrFail($reservation), $actor)]);
        });
    }

    public function store(Request $request, int $reservation, RentalPickup $pickups): JsonResponse
    {
        $input = $request->validate(['late_agreement_confirmed' => ['sometimes', 'boolean'], 'late_agreement_reason' => ['nullable', 'string', 'max:1000'], 'picked_up_local' => ['required', 'date_format:Y-m-d\TH:i'], 'review_fingerprint' => ['required', 'string', 'size:64'], 'keys_handed_over' => ['required', 'accepted'], 'vehicle_present' => ['required', 'accepted'], 'driver_reviewed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'demo_simulation' => ['sometimes', 'boolean'], 'simulation_attested' => ['exclude_unless:demo_simulation,true', 'required', 'accepted'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'contract_demo_override' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'accepted' => 'Confirmez le contrôle réel avant la remise.', 'date_format' => 'Saisissez la date et l’heure réelles de remise.']);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        try {
            $result = $pickups->record($reservation, $actor, $input);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23P01', '23505'], true)) {
                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'Un autre départ ou une occupation existe déjà. Actualisez le dossier.'], 409);
            }throw $e;
        }
        if (isset($result['error'])) {
            return response()->json(['code' => $result['error'], 'message' => $result['message'], 'checks' => $result['checks'] ?? []], $result['status']);
        }

        return response()->json(['data' => $pickups->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }
}
