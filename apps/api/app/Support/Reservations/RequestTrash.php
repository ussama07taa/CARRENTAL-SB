<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Reversible request disposal, projected from the existing append-only event log. */
class RequestTrash
{
    public static function scope(Builder $query, bool $trashed = false): void
    {
        $latest = DB::table('reservation_events')->selectRaw('DISTINCT ON (reservation_id) reservation_id, action')
            ->where('company_id', Tenant::id())->whereIn('action', ['request_trashed', 'request_restored'])->orderBy('reservation_id')->orderByDesc('id');
        $ids = DB::query()->fromSub($latest, 'trash_state')->select('reservation_id')->where('action', 'request_trashed');
        $query->whereIn('reservations.id', $ids, 'and', ! $trashed);
    }

    public function latest(int $id): ?object
    {
        return DB::table('reservation_events')->where('company_id', Tenant::id())->where('reservation_id', $id)->whereIn('action', ['request_trashed', 'request_restored'])->orderByDesc('id')->first();
    }

    public function eligibility(Reservation $r): array
    {
        $latest = $this->latest($r->id);
        $trashed = $latest?->action === 'request_trashed';
        $restoreStatus = $trashed && (json_decode($latest->data, true)['from'] ?? null) === 'annulee' ? 'annulee' : 'demande';
        $reason = null;
        if (! in_array($r->status, $trashed ? ['annulee'] : ['demande', 'annulee'], true)) {
            $reason = 'Seule une demande non confirmée peut être placée dans la corbeille.';
        } elseif ($r->confirmed_at || $r->frozen_total_centimes !== null) {
            $reason = 'Ce dossier a déjà été confirmé. Conservez son historique.';
        } else {
            // Inspect every direct business relation, including pending transfers, deposit,
            // quotes, inspections, issued documents and future modules with a reservation_id.
            $tables = DB::table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'reservation_id')->whereNotIn('table_name', ['reservation_events'])->pluck('table_name');
            $quick = DB::table('reservation_events')->where('company_id', Tenant::id())->where('reservation_id', $r->id)->where('action', 'request_cancelled_quick')->orderByDesc('id')->first();
            $quickKey = $quick ? (json_decode($quick->data, true)['request_key'] ?? null) : null;
            foreach ($tables as $table) {
                $relations = DB::table($table)->where('company_id', Tenant::id())->where('reservation_id', $r->id);
                // Preserve (and only exempt) the zero-value records created by our
                // quick cancellation of an already-empty request. Everything else blocks.
                if ($r->status === 'annulee' && $quickKey && in_array($table, ['rental_cancellations', 'rental_settlement_entries'], true)) {
                    $relations->whereNot(function ($allowed) use ($table, $quickKey) {
                        $allowed->where('request_key', $quickKey);
                        if ($table === 'rental_cancellations') {
                            $allowed->where('final_total_centimes', 0);
                        } else {
                            $allowed->where('kind', 'rent_total')->where('scope', 'rent')->where('amount_centimes', 0);
                        }
                    });
                }
                if ($relations->exists()) {
                    $reason = 'Ce dossier contient des opérations, montants ou documents liés. Utilisez son annulation et son règlement.';
                    break;
                }
            }
        }

        return ['restore_status' => $restoreStatus, 'cancelled' => $r->status === 'annulee', 'trashed' => $trashed, 'can_trash' => ! $trashed && ! $reason && $r->source === 'agency', 'can_cancel_quick' => ! $trashed && ! $reason && $r->status === 'demande', 'can_restore' => $trashed && ! $reason, 'reason' => $reason ?: ($r->source !== 'agency' ? 'Les demandes du site se conservent par annulation.' : null)];
    }

    public function perform(int $id, CompanyMembership $actor, array $input): array
    {
        abort_unless($actor->is_primary_responsible, 403, 'Cette action est réservée au responsable.');

        return DB::transaction(function () use ($id, $actor, $input) {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $r = Reservation::withoutGlobalScope('request_trash')->whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$id, $input]));
            $old = DB::table('reservation_events')->where('company_id', Tenant::id())->whereIn('action', ['request_trashed', 'request_restored', 'request_cancelled_quick'])->whereRaw("data->>'request_key' = ?", [$input['request_key']])->first();
            if ($old) {
                abort_unless((int) $old->reservation_id === $id && hash_equals(json_decode($old->data, true)['request_hash'], $hash), 409, 'Cette opération correspond à une autre demande.');

                return ['replayed' => true];
            }
            $eligibility = $this->eligibility($r);
            $action = $input['action'];
            if (! $eligibility['can_'.$action]) {
                abort(response()->json(['code' => 'UNTRANSITIONABLE', 'message' => $eligibility['reason'] ?? 'Cette action n’est plus possible. Actualisez la liste.'], 422));
            }
            $from = $r->status;
            if ($action === 'cancel_quick') {
                // Same cancellation ledger and audit rules as the existing dossier action.
                app(RentalCancellation::class)->perform($id, $actor, ['final_total_centimes' => 0, 'expected_revision' => null, 'reason' => $input['reason'], 'agreement_confirmed' => true, 'no_vehicle_handed_over' => true, 'request_key' => $input['request_key']]);
                $event = 'request_cancelled_quick';
                $to = 'annulee';
            } else {
                $to = $action === 'restore' ? $eligibility['restore_status'] : 'annulee';
                $event = $action === 'restore' ? 'request_restored' : 'request_trashed';
                DB::table('reservations')->where('company_id', Tenant::id())->where('id', $id)->update(['status' => $to]);
            }
            app(RentalContract::class)->event($id, $actor->id, $event, ['from' => $from, 'to' => $to, 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'money_changed' => false, 'blocks_vehicle' => false]);

            return ['replayed' => false];
        });
    }
}
