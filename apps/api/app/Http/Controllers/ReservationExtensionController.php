<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalExtension;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationExtensionController extends Controller
{
    private function actor(Request $request): CompanyMembership
    {
        return CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function show(Request $request, int $reservation, RentalExtension $extensions): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $extensions): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json(['data' => $extensions->summary(Reservation::findOrFail($reservation), $this->actor($request))]);
        });
    }

    public function quote(Request $request, int $reservation, RentalExtension $extensions): JsonResponse
    {
        $input = $request->validate(['ends_local' => ['required', 'date_format:Y-m-d\TH:i']]);
        abort_unless($this->actor($request)->is_primary_responsible, 403);

        return response()->json(['data' => $extensions->quote(Reservation::findOrFail($reservation), $input['ends_local'])]);
    }

    public function store(Request $request, int $reservation, RentalExtension $extensions): JsonResponse
    {
        $input = $request->validate(['ends_local' => ['required', 'date_format:Y-m-d\TH:i'], 'expected_latest_id' => ['present', 'nullable', 'integer', 'min:1'], 'fingerprint' => ['required', 'string', 'size:64'], 'amount_centimes' => ['required', 'integer', 'between:0,99999999999'], 'exception_reason' => ['nullable', 'string', 'min:3', 'max:1000'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'agreement_confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'blocked_until' => ['prohibited'], 'preparation_minutes' => ['prohibited']]);
        $actor = $this->actor($request);
        $replayed = $extensions->propose($reservation, $actor, $input);

        return response()->json(['data' => $extensions->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function activate(Request $request, int $reservation, int $agreement, RentalExtension $extensions): JsonResponse
    {
        $input = $request->validate(['contract_id' => ['required', 'integer', 'min:1'], 'signed_file_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'checked' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'ends_at' => ['prohibited'], 'amount_centimes' => ['prohibited']]);
        $actor = $this->actor($request);
        try {
            $replayed = $extensions->activate($reservation, $agreement, $actor, $input);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'La voiture vient d’être réservée sur cette période. Le retour initial et les fonds reçus restent conservés.'], 409);
            }
            throw $exception;
        }

        return response()->json(['data' => $extensions->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
