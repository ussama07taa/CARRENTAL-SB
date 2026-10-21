<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationDriverController extends Controller
{
    public function create(\App\Http\Requests\CreateRentalDriverRequest $request, int $reservation, RentalDriver $drivers): JsonResponse
    {
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $replayed = $drivers->createAndAssign($reservation, $actor, $request->validated());
        return response()->json(['data' => collect($drivers->summary(Reservation::findOrFail($reservation)))->except('snapshot'), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function show(int $reservation, RentalDriver $drivers): JsonResponse
    {
        return DB::transaction(function () use ($reservation, $drivers): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            return response()->json(['data' => collect($drivers->summary(Reservation::findOrFail($reservation)))->except('snapshot')]);
        });
    }

    public function store(Request $request, int $reservation, RentalDriver $drivers): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', Rule::in(['assigned', 'verified', 'removed'])],
            'expected_revision' => ['present', 'nullable', 'integer', 'min:1'],
            'customer_id' => ['required_if:action,assigned', 'prohibited_unless:action,assigned', 'integer', 'min:1'],
            'fingerprint' => ['required_if:action,verified', 'prohibited_unless:action,verified', 'string', 'size:64'],
            'identity_original' => ['required_if:action,verified', 'prohibited_unless:action,verified', 'accepted_if:action,verified'],
            'licence_original' => ['required_if:action,verified', 'prohibited_unless:action,verified', 'accepted_if:action,verified'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'],
            'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'],
        ], ['required' => 'Ce champ est obligatoire.', 'required_if' => 'Ce champ est obligatoire pour cette action.', 'accepted_if' => 'Confirmez le contrôle de l’original.', 'prohibited_unless' => 'Ce champ ne correspond pas à cette action.']);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $replayed = $drivers->save($reservation, $actor, $input);
        return response()->json(['data' => collect($drivers->summary(Reservation::findOrFail($reservation)))->except('snapshot'), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
