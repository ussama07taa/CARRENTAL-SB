<?php

namespace App\Http\Controllers;

use App\Actions\Customers\SaveCustomer;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Support\Customers\CustomerFields;
use App\Support\Reservations\DeparturePreparation;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationDepartureController extends Controller
{
    public function customer(Request $request, int $reservation, SaveCustomer $save, DeparturePreparation $departure): JsonResponse
    {
        abort_unless($departure->ready(), 503, 'La préparation du départ attend l’activation de la mise à jour.');
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'identity_type' => ['required', Rule::in(['cin', 'passport'])], 'identity_number' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN]+$/u'], 'licence_number' => ['required', 'string', 'max:50'], 'licence_expires_on' => ['required', 'date_format:Y-m-d'], 'company_id' => ['prohibited']]);

        return DB::transaction(function () use ($request, $reservation, $input, $save): JsonResponse {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::findOrFail($reservation);
            abort_unless($record->status === 'confirmee', 422, 'Confirmez la réservation avant de préparer le départ.');
            $customer = Customer::whereKey($record->customer_id)->lockForUpdate()->firstOrFail();
            $input['identity_number'] = CustomerFields::identity($input['identity_number']);
            $save->handle([...$customer->only(CustomerFields::EDITABLE), ...$input], $request->user()->id, $customer->id);

            return response()->json(['saved' => true]);
        });
    }

    public function show(Request $request, int $reservation, DeparturePreparation $departure): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $departure): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();

            return response()->json(['data' => $departure->summary(Reservation::findOrFail($reservation), $actor)]);
        });
    }

    public function store(Request $request, int $reservation, DeparturePreparation $departure): JsonResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['documents_checked', 'caution_set', 'caution_waived', 'caution_received', 'caution_verified'])]])['action'];
        $rules = match ($action) {
            'documents_checked' => ['fingerprint' => ['required', 'string', 'size:64'], 'identity_original' => ['required', 'accepted'], 'licence_original' => ['required', 'accepted']],
            'caution_set' => ['amount_centimes' => ['required', 'integer', 'min:1', 'max:99999999999'], 'expected_terms_id' => ['present', 'nullable', 'integer', 'min:1']],
            'caution_waived' => ['expected_terms_id' => ['present', 'nullable', 'integer', 'min:1']],
            'caution_received' => ['amount_centimes' => ['required', 'integer', 'min:1', 'max:99999999999'], 'method' => ['required', Rule::in(['especes', 'virement', 'carte_bloquee'])], 'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:200', 'required_if:method,virement,carte_bloquee'], 'receipt_attested' => ['required', 'accepted']],
            'caution_verified' => ['receipt_id' => ['required', 'integer', 'min:1'], 'receipt_attested' => ['required', 'accepted']],
        };
        $input = $request->validate([...$rules, 'request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'company_id' => ['prohibited'], 'verified' => ['prohibited'], 'actor_membership_id' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'accepted' => 'Confirmez le contrôle ou la réception réelle.', 'min' => 'La valeur est insuffisante.', 'integer' => 'Saisissez un nombre entier valide.', 'before_or_equal' => 'La date ne peut pas être dans le futur.', 'required_if' => 'La référence est obligatoire pour cette méthode.']);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $result = $departure->save($reservation, $actor, $action, $input);

        return response()->json(['data' => $departure->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }
}
