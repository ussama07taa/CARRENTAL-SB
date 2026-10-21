<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RentalCancellation
{
    public function perform(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($actor->is_primary_responsible, 403, 'L’annulation et son accord financier sont réservés au responsable.');
        abort_unless(Schema::hasTable('rental_cancellations'), 503, 'L’annulation attend l’activation de la mise à jour.');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = DB::table('rental_cancellations')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless($old->reservation_id === $id && hash_equals($old->request_hash, $hash), 409, 'Cette annulation correspond à d’autres données.');

                return true;
            }
            if (! in_array($record->status, ['demande', 'temporaire', 'confirmee'], true) || DB::table('rental_pickups')->where('company_id', Tenant::id())->where('reservation_id', $id)->exists()) {
                abort(response()->json(['code' => 'UNTRANSITIONABLE', 'message' => 'Après remise de la voiture, enregistrez son retour réel. Une annulation ne remplace pas le retour.'], 422));
            }
            $settlements = app(RentalSettlement::class);
            $before = $settlements->summary($record, $actor);
            abort_unless((int) ($before['revision'] ?? 0) === (int) ($input['expected_revision'] ?? 0), 409, 'Le règlement a changé. Actualisez avant d’annuler.');
            $from = $record->status;
            DB::table('rental_cancellations')->insert(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'final_total_centimes' => $input['final_total_centimes'], 'reason' => $input['reason'], 'snapshot' => json_encode(['reservation' => $record->getAttributes(), 'financial' => $before['rent'], 'agreement_confirmed' => true]), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            DB::table('reservations')->where('company_id', Tenant::id())->where('id', $id)->update(['status' => 'annulee']);
            $settlements->perform($id, $actor, ['kind' => 'rent_total', 'scope' => 'rent', 'amount_centimes' => $input['final_total_centimes'], 'expected_revision' => $input['expected_revision'], 'reason' => $input['reason'], 'occurred_on' => now(RequestPeriod::TIMEZONE)->toDateString(), 'agreement_confirmed' => true, 'checked' => true, 'request_key' => $input['request_key']]);
            app(RentalContract::class)->event($id, $actor->id, 'reservation_cancelled', ['from' => $from, 'to' => 'annulee', 'final_total_centimes' => (int) $input['final_total_centimes'], 'reason' => $input['reason'], 'refund_automatically_paid' => false]);

            return false;
        });
    }
}
