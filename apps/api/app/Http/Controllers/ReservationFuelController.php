<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\FuelSettlement;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationFuelController extends Controller
{
    private function actor(Request $request): CompanyMembership
    {
        return CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function show(Request $request, int $reservation, FuelSettlement $fuel): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $fuel): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json(['data' => $fuel->summary(Reservation::findOrFail($reservation), $this->actor($request))]);
        });
    }

    public function charge(Request $request, int $reservation, FuelSettlement $fuel): JsonResponse
    {
        return $this->save($request, $reservation, $fuel, 'charge', ['inspection_id' => ['required', 'integer', 'min:1'], 'amount_centimes' => ['required', 'integer', 'between:1,99999999999'], 'ticket_reference' => ['required', 'string', 'min:3', 'max:200'], 'ticket' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:5120'], 'agreement_confirmed' => ['required', 'accepted']]);
    }

    public function payment(Request $request, int $reservation, FuelSettlement $fuel): JsonResponse
    {
        return $this->save($request, $reservation, $fuel, 'payment', ['amount_centimes' => ['required', 'integer', 'between:1,99999999999'], 'method' => ['required', Rule::in(['especes', 'carte', 'virement'])], 'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['nullable', 'required_if:method,carte,virement', 'string', 'max:200'], 'receipt_attested' => ['required', 'accepted']]);
    }

    public function verify(Request $request, int $reservation, int $payment, FuelSettlement $fuel): JsonResponse
    {
        return $this->save($request, $reservation, $fuel, 'verify', ['receipt_attested' => ['required', 'accepted']], $payment);
    }

    private function save(Request $request, int $id, FuelSettlement $fuel, string $operation, array $rules, ?int $payment = null): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->is_primary_responsible, 403, 'Cette action est réservée au responsable.');
        Reservation::findOrFail($id);
        $input = $request->validate([...$rules, 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'status' => ['prohibited'], 'verified' => ['prohibited'], 'frozen_total_centimes' => ['prohibited']]);
        unset($input['ticket']);
        $replayed = $fuel->perform($id, $actor, $operation, $input, $request->file('ticket'), $payment);

        return response()->json(['data' => $fuel->summary(Reservation::findOrFail($id), $actor), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function ticket(int $reservation, int $charge): StreamedResponse
    {
        Reservation::findOrFail($reservation);
        $record = DB::table('rental_fuel_charges')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('id', $charge)->first();
        abort_unless($record && $record->ticket_path && Storage::disk('rental_returns')->exists($record->ticket_path), 404);
        $extension = match ($record->ticket_mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };

        return Storage::disk('rental_returns')->response($record->ticket_path, 'ticket-carburant-'.$charge.'.'.$extension, ['Content-Type' => $record->ticket_mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store, max-age=0']);
    }
}
