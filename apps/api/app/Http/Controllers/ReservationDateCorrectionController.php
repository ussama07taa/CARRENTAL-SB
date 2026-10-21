<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\RequestAvailability;
use App\Support\Reservations\RequestPeriod;
use App\Support\Reservations\RentalContract;
use App\Support\Reservations\TemporaryHold;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReservationDateCorrectionController extends Controller
{
    public function store(Request $request, int $reservation)
    {
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($actor->is_primary_responsible, 403);
        abort_unless(Schema::hasTable('rental_date_corrections'), 503, 'La correction des dates attend son activation. Le dossier reste conservé.');
        $input = $request->validate([
            'starts_local' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_local' => ['required', 'date_format:Y-m-d\TH:i'],
            'expected_starts_local' => ['required', 'date_format:Y-m-d\TH:i'], 'expected_ends_local' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'], 'agreement_confirmed' => ['required', 'accepted'],
            'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'amount_centimes' => ['prohibited'],
        ]);
        $hash = hash('sha256', json_encode([$reservation, $input]));
        try {
            $replayed = DB::transaction(function () use ($request, $reservation, $actor, $input, $hash): bool {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $r = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
                Vehicle::whereKey($r->vehicle_id)->lockForUpdate()->firstOrFail();
                $old = DB::table('rental_date_corrections')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à une autre correction.');
                    return true;
                }
                abort_unless($r->status === 'confirmee' && ! $r->active_swap_id && ! $r->active_extension_id && ! DB::table('rental_pickups')->where('company_id', Tenant::id())->where('reservation_id', $r->id)->exists(), 422, 'La correction concerne une réservation confirmée avant tout départ, remplacement ou prolongation.');
                $local = fn ($date) => $date->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i');
                abort_unless($local($r->starts_at) === $input['expected_starts_local'] && $local($r->ends_at) === $input['expected_ends_local'], 409, 'Les dates ont changé. Actualisez le dossier avant de corriger.');
                app(RequestPeriod::class)->validate($request);
                $start = app(RequestPeriod::class)->instant($input['starts_local'], 'starts_local');
                $end = app(RequestPeriod::class)->instant($input['ends_local'], 'ends_local');
                abort_unless($end > now() && $start->diffInSeconds($end) === $r->starts_at->diffInSeconds($r->ends_at), 422, 'Cette correction doit conserver exactement la durée convenue et un retour futur.');
                abort_if($start->equalTo($r->starts_at) && $end->equalTo($r->ends_at), 422, 'Les dates sont identiques.');
                $candidate = clone $r;
                $candidate->starts_at = $start;
                $candidate->ends_at = $end;
                $candidate->blocked_until = $end->addMinutes($r->preparation_minutes);
                $availability = app(RequestAvailability::class);
                if (app(TemporaryHold::class)->conflicts($candidate) || in_array($r->vehicle_id, $availability->blockedVehicles(), true)) {
                    abort(response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'La voiture est indisponible sur les dates corrigées. Les dates et paiements restent conservés.'], 409));
                }
                $id = DB::table('rental_date_corrections')->insertGetId([
                    'company_id' => Tenant::id(), 'reservation_id' => $r->id, 'vehicle_id' => $r->vehicle_id, 'actor_membership_id' => $actor->id,
                    'previous_id' => $r->active_date_correction_id, 'old_starts_at' => $r->starts_at, 'old_ends_at' => $r->ends_at,
                    'new_starts_at' => $start, 'new_ends_at' => $end, 'new_blocked_until' => $candidate->blocked_until,
                    'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now(),
                ]);
                $before = ['starts_local' => $local($r->starts_at), 'ends_local' => $local($r->ends_at)];
                DB::table('reservations')->where('company_id', Tenant::id())->where('id', $r->id)->update(['starts_at' => $start, 'ends_at' => $end, 'blocked_until' => $candidate->blocked_until, 'active_date_correction_id' => $id]);
                app(RentalContract::class)->event($r->id, $actor->id, 'reservation_dates_corrected', ['correction_id' => $id, 'before' => $before, 'after' => ['starts_local' => $input['starts_local'], 'ends_local' => $input['ends_local']], 'reason' => $input['reason'], 'total_centimes' => $r->frozen_total_centimes]);
                return false;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'Ces dates chevauchent une réservation. Le dossier initial reste conservé.'], 409);
            }
            throw $e;
        }
        return response()->json(['replayed' => $replayed, 'message' => 'Dates corrigées. Préparez et faites signer une nouvelle version du contrat.'], $replayed ? 200 : 201);
    }
}
