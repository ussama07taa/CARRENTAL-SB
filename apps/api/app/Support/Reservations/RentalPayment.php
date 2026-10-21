<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RentalPayment
{
    public function ready(): bool
    {
        return Schema::hasTable('reservation_confirmations');
    }

    public function table(string $name): Builder
    {
        return DB::table($name)->where('company_id', Tenant::id());
    }

    public function summary(Reservation $record): array
    {
        if (! $this->ready()) {
            return ['ready' => false];
        }
        $quote = $this->table('reservation_quotes')->where('reservation_id', $record->id)->latest('id')->first();
        $effects = app(RentalSettlement::class)->effects($record->id, 'rent');
        $payments = $this->table('rental_payments')->where('reservation_id', $record->id)->orderByDesc('id')->get();
        $active = $payments->reject(fn ($p) => in_array($p->id, $effects['void_ids'], true));
        $checks = $this->table('rental_payment_verifications')->where('reservation_id', $record->id)->get()->keyBy('payment_id');
        $verified = (int) $active->filter(fn ($p) => $checks->has($p->id))->sum('amount_centimes') + $effects['allocated'] - $effects['refunded'];
        $pending = (int) $active->reject(fn ($p) => $checks->has($p->id))->sum('amount_centimes');
        $total = $effects['total'] ?? $record->frozen_total_centimes ?? $quote?->total_centimes;
        $swapAmount = app(RentalVehicleSwap::class)->priceAddition($record->id, $effects['swap_revision']);
        if ($total !== null) {
            $total += $swapAmount + app(RentalExtension::class)->priceAddition($record->id, $effects['extension_revision']);
        }
        $priceEvent = $quote ? $this->table('reservation_events')->where('reservation_id', $record->id)->where('action', 'rental_price_agreed')->where('data->quote_id', $quote->id)->latest('id')->first() : null;
        $pricingSnapshot = $priceEvent ? (json_decode($priceEvent->data, true)['pricing_snapshot'] ?? null) : null;

        return ['ready' => true, 'pricing_snapshot' => $pricingSnapshot, 'quote_reason' => $quote?->reason, 'quote_id' => $quote?->id, 'initial_total_centimes' => ($record->frozen_total_centimes ?? $quote?->total_centimes) === null ? null : (int) ($record->frozen_total_centimes ?? $quote?->total_centimes), 'regularized' => $effects['total'] !== null || $record->active_swap_id !== null || $record->active_extension_id !== null, 'swap_difference_centimes' => $swapAmount, 'total_centimes' => $total === null ? null : (int) $total, 'verified_centimes' => $verified, 'pending_centimes' => $pending, 'remaining_centimes' => $total === null ? null : max(0, (int) $total - $verified), 'surplus_centimes' => $total === null ? 0 : max(0, $verified - (int) $total), 'frozen' => $record->confirmed_at !== null, 'confirmed_at' => $record->confirmed_at, 'payments' => $payments->map(fn ($p) => ['id' => $p->id, 'amount_centimes' => (int) $p->amount_centimes, 'method' => $p->method, 'received_on' => $p->received_on, 'reference' => $p->reference, 'note' => $p->note, 'status' => in_array($p->id, $effects['void_ids'], true) ? 'void' : ($checks->has($p->id) ? 'verified' : 'pending'), 'recorded_at' => \Carbon\CarbonImmutable::parse($p->created_at)->toIso8601String(), 'verified_at' => $checks->get($p->id)?->created_at])->values()->all()];
    }

    private function event(int $id, int $actor, string $action, array $data): void
    {
        DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor, 'action' => $action, 'data' => json_encode($data), 'created_at' => now()]);
    }

    private function failure(string $code, string $message, int $status = 422): array
    {
        return ['error' => $code, 'message' => $message, 'status' => $status];
    }

    public function perform(int $id, CompanyMembership $actor, string $operation, array $input, ?int $paymentId = null): array
    {
        abort_unless($this->ready(), 503, 'Les paiements nécessitent l’activation de la mise à jour de la base.');
        if (in_array($operation, ['quote', 'verify'], true)) {
            abort_unless($actor->is_primary_responsible, 403, 'Cette action est réservée au responsable.');
        }
        $table = ['quote' => 'reservation_quotes', 'payment' => 'rental_payments', 'verify' => 'rental_payment_verifications', 'confirm' => 'reservation_confirmations'][$operation];
        $hash = hash('sha256', json_encode([$id, $paymentId, $input]));

        return DB::transaction(function () use ($id, $actor, $operation, $input, $paymentId, $table, $hash): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = $this->table($table)->where('request_key', $input['request_key'])->first();
            if ($old) {
                return hash_equals($old->request_hash, $hash) ? ['replayed' => true] : $this->failure('REQUEST_ALREADY_USED', 'Cette opération correspond à des données différentes.', 409);
            }
            $base = ['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()];
            if ($operation === 'quote') {
                if ($record->status === 'annulee') {
                    return $this->failure('UNTRANSITIONABLE', 'Ce dossier est annulé. Conservez son accord et ses règlements.');
                }
                if ($record->confirmed_at !== null) {
                    return $this->failure('PRICE_FROZEN', 'Le prix est figé depuis la confirmation.');
                }
                $current = $this->table('reservation_quotes')->where('reservation_id', $id)->max('id');
                if ((int) $current !== (int) ($input['expected_quote_id'] ?? 0)) {
                    return $this->failure('QUOTE_CHANGED', 'Le prix a été modifié. Actualisez le dossier.', 409);
                }
                $pricingSnapshot = null;
                if (isset($input['pricing_version_id'])) {
                    $pricing = app(VehiclePricing::class);
                    if ((int) ($pricing->current()?->id ?? 0) !== (int) $input['pricing_version_id']) {
                        return $this->failure('PRICING_CHANGED', 'La grille tarifaire a changé. Actualisez le calcul et revérifiez le prix avec le client.', 409);
                    }
                    $pricingSnapshot = $pricing->forReservation($record);
                    if (($input['use_calculated_price'] ?? false) && (int) $input['total_centimes'] !== $pricingSnapshot['total_centimes']) {
                        return $this->failure('CALCULATION_CHANGED', 'Le montant ne correspond pas au calcul de ces dates. Actualisez le calcul.', 409);
                    }
                    $pricingSnapshot['agreed_total_centimes'] = (int) $input['total_centimes'];
                    $pricingSnapshot['difference_centimes'] = (int) $input['total_centimes'] - $pricingSnapshot['total_centimes'];
                    $pricingSnapshot['agreement_mode'] = ($input['use_calculated_price'] ?? false) ? 'calculated' : 'negotiated';
                }
                $quoteId = DB::table($table)->insertGetId([...$base, 'total_centimes' => $input['total_centimes'], 'reason' => $input['reason']]);
                $this->event($id, $actor->id, 'rental_price_agreed', ['quote_id' => $quoteId, 'total_centimes' => $input['total_centimes'], 'reason' => $input['reason'], 'pricing_snapshot' => $pricingSnapshot]);
            } elseif ($operation === 'payment') {
                $similar = $this->table('rental_payments')->where('reservation_id', $id)
                    ->where('amount_centimes', $input['amount_centimes'])->where('method', $input['method'])
                    ->where('received_on', $input['received_on'])->where('created_at', '>=', now()->subMinutes(10))
                    ->whereNotIn('id', app(RentalSettlement::class)->effects($id, 'rent')['void_ids'])->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                $reviewed = array_map('intval', $input['similar_payment_ids'] ?? []);
                sort($reviewed);
                if ($similar && (! ($input['distinct_payment_confirmed'] ?? false) || $reviewed !== $similar)) {
                    return [...$this->failure('SIMILAR_PAYMENT', 'Un paiement similaire a été enregistré dans les 10 dernières minutes. Vérifiez-le et confirmez uniquement s’il s’agit d’un autre versement.', 409), 'similar_payment_ids' => $similar];
                }
                $payment = DB::table($table)->insertGetId([...$base, ...collect($input)->only(['amount_centimes', 'method', 'received_on', 'reference', 'note'])->all()]);
                if ($input['method'] !== 'virement') {
                    DB::table('rental_payment_verifications')->insert([...$base, 'payment_id' => $payment, 'reason' => 'Réception effective attestée lors de l’enregistrement.']);
                }
                $this->event($id, $actor->id, 'rental_payment_recorded', ['payment_id' => $payment, 'amount_centimes' => $input['amount_centimes'], 'method' => $input['method'], 'verified' => $input['method'] !== 'virement', 'similar_payment_ids' => $similar, 'distinct_payment_confirmed' => (bool) ($similar && ($input['distinct_payment_confirmed'] ?? false))]);
            } elseif ($operation === 'verify') {
                $payment = $this->table('rental_payments')->where('reservation_id', $id)->where('id', $paymentId)->first();
                abort_unless($payment, 404);
                abort_if(in_array($paymentId, app(RentalSettlement::class)->effects($id, 'rent')['void_ids'], true), 422, 'Cette écriture a été annulée.');
                if ($this->table($table)->where('payment_id', $paymentId)->exists()) {
                    return $this->failure('ALREADY_VERIFIED', 'Ce paiement a déjà été vérifié.');
                }
                DB::table($table)->insert([...$base, 'payment_id' => $paymentId, 'reason' => $input['reason']]);
                $this->event($id, $actor->id, 'rental_transfer_verified', ['payment_id' => $paymentId, 'amount_centimes' => (int) $payment->amount_centimes, 'reason' => $input['reason']]);
            } else {
                $wasExpired = $record->status === 'temporaire' && $record->hold_expires_at <= now();
                $holds = app(TemporaryHold::class);
                $holds->expireLocked();
                $record->refresh();
                if (in_array($record->status, ['confirmee', 'en_location', 'retournee', 'annulee'], true)) {
                    return $this->failure('UNTRANSITIONABLE', 'Cette réservation est déjà confirmée.');
                }
                if ($wasExpired || ($record->status === 'demande' && $record->hold_expires_at !== null)) {
                    return $this->failure('HOLD_EXPIRED', 'Le délai a expiré. Les paiements sont conservés. Réactivez un blocage après vérification de la disponibilité.', 409);
                }
                if ($record->ends_at <= now()) {
                    return $this->failure('UNTRANSITIONABLE', 'La période de location est déjà terminée. Le paiement reste conservé ; revoyez le dossier avant de confirmer.');
                }
                $summary = $this->summary($record);
                if (! $summary['quote_id'] || (int) $summary['quote_id'] !== (int) $input['quote_id']) {
                    return $this->failure('QUOTE_CHANGED', 'Enregistrez et vérifiez le prix actuel avant de confirmer.', 409);
                }
                if ($summary['remaining_centimes'] !== 0) {
                    return $this->failure('PAYMENT_INCOMPLETE', 'Le montant intégral du loyer doit être reçu et vérifié.');
                }
                if ($holds->conflicts($record) || in_array($record->vehicle_id, app(RequestAvailability::class)->blockedVehicles(), true)) {
                    return $this->failure('UNIT_UNAVAILABLE', 'La voiture n’est plus disponible. Les paiements reçus restent conservés.', 409);
                }
                if ($record->status === 'temporaire' && $record->hold_expires_at <= now()) {
                    $holds->expireLocked();

                    return $this->failure('HOLD_EXPIRED', 'Le délai vient d’expirer. Les paiements sont conservés. Réactivez un blocage après vérification de la disponibilité.', 409);
                }
                $minutes = $record->status === 'temporaire' ? $record->preparation_minutes : $holds->preparationMinutes();
                $until = $record->ends_at->addMinutes($minutes);
                $snapshot = ['customer' => Customer::findOrFail($record->customer_id)->only(['id', 'first_name', 'last_name', 'phone']), 'vehicle' => Vehicle::findOrFail($record->vehicle_id)->only(['id', 'plate', 'make', 'model']), 'starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'total_centimes' => $summary['total_centimes'], 'verified_centimes' => $summary['verified_centimes'], 'quote_id' => $summary['quote_id'], 'pricing_snapshot' => $summary['pricing_snapshot'], 'blocked_until' => $until->toIso8601String(), 'preparation_minutes' => $minutes];
                DB::table('reservations')->where('company_id', Tenant::id())->where('id', $id)->update(['status' => 'confirmee', 'confirmed_at' => now(), 'frozen_total_centimes' => $summary['total_centimes'], 'blocked_until' => $until, 'preparation_minutes' => $minutes]);
                DB::table($table)->insert([...$base, 'quote_id' => $summary['quote_id'], 'snapshot' => json_encode($snapshot)]);
                $this->event($id, $actor->id, 'reservation_confirmed', ['from' => $record->status, 'to' => 'confirmee', ...$snapshot]);
            }

            return ['replayed' => false];
        });
    }
}
