<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RentalSettlement
{
    public function ready(): bool
    {
        return Schema::hasTable('rental_settlement_entries');
    }

    public function entries(int $id): Builder
    {
        return DB::table('rental_settlement_entries')->where('company_id', Tenant::id())->where('reservation_id', $id);
    }

    public function effects(int $id, string $scope): array
    {
        $rows = $this->ready() ? $this->entries($id)->where('scope', $scope)->orderBy('id')->get() : collect();

        return $this->calculateEffects($rows, $scope);
    }

    private function calculateEffects(Collection $rows, string $scope): array
    {
        $rows = $rows->where('scope', $scope);
        $adjustment = $rows->whereIn('kind', ['fuel_amount', 'rent_total'])->last();
        $reference = $adjustment ? json_decode($adjustment->snapshot, true) : [];

        return ['extension_revision' => (int) ($reference['extension_revision'] ?? 0), 'swap_revision' => (int) ($reference['swap_revision'] ?? 0), 'fuel_charge_revision' => (int) ($reference['fuel_charge_revision'] ?? 0), 'total' => $adjustment?->amount_centimes, 'void_ids' => $rows->where('kind', 'payment_void')->pluck($scope === 'fuel' ? 'fuel_payment_id' : 'rent_payment_id')->all(), 'allocated' => (int) $rows->where('kind', 'deposit_allocate')->sum('amount_centimes'), 'refunded' => (int) $rows->where('kind', 'refund_paid')->sum('amount_centimes'), 'refund_reserved' => (int) $rows->where('kind', 'refund_approved')->sum('amount_centimes') - (int) $rows->whereIn('kind', ['refund_paid', 'refund_cancelled'])->sum('amount_centimes')];
    }

    public function briefs(Collection $records): array
    {
        if (! $this->ready() || $records->isEmpty()) {
            return [];
        }
        $ids = $records->pluck('id');
        $table = fn (string $name) => DB::table($name)->where('company_id', Tenant::id())->whereIn('reservation_id', $ids);
        $entries = $table('rental_settlement_entries')->orderBy('id')->get()->groupBy('reservation_id');
        $quotes = $table('reservation_quotes')->orderByDesc('id')->get()->unique('reservation_id')->keyBy('reservation_id');
        $charges = $table('rental_fuel_charges')->get()->groupBy('reservation_id');
        $rent = $table('rental_payments')->get()->groupBy('reservation_id');
        $rentVerified = $table('rental_payment_verifications')->pluck('payment_id')->all();
        $fuel = $table('rental_fuel_payments')->get()->groupBy('reservation_id');
        $fuelVerified = $table('rental_fuel_verifications')->pluck('payment_id')->all();
        $deposits = $table('reservation_departure_entries')->get()->groupBy('reservation_id');
        $swaps = app(RentalVehicleSwap::class)->ready() ? $table('rental_vehicle_swaps')->get()->groupBy('reservation_id') : collect();
        $extensions = app(RentalExtension::class)->ready() ? $table('rental_extension_activations')->get()->groupBy('reservation_id') : collect();
        $extensionAmounts = app(RentalExtension::class)->ready() ? $table('rental_extension_agreements')->get()->keyBy('id') : collect();
        $result = [];
        foreach ($records as $record) {
            $rows = $entries->get($record->id, collect());
            $remaining = 0;
            $credit = 0;
            $pending = 0;
            foreach (['rent', 'fuel'] as $scope) {
                $effect = $this->calculateEffects($rows, $scope);
                $payments = ($scope === 'rent' ? $rent : $fuel)->get($record->id, collect())->reject(fn ($p) => in_array($p->id, $effect['void_ids'], true));
                $verified = $scope === 'rent' ? $rentVerified : $fuelVerified;
                $paid = (int) $payments->filter(fn ($p) => in_array($p->id, $verified, true))->sum('amount_centimes') + $effect['allocated'] - $effect['refunded'];
                $total = (int) ($effect['total'] ?? ($scope === 'rent' ? ($record->frozen_total_centimes ?? $quotes->get($record->id)?->total_centimes) : $charges->get($record->id, collect())->sum('amount_centimes')) ?? 0);
                if ($scope === 'rent') {
                    $total += (int) $swaps->get($record->id, collect())->where('id', '>', $effect['swap_revision'])->sum('difference_centimes');
                    $total += (int) $extensions->get($record->id, collect())->where('id', '>', $effect['extension_revision'])->sum(fn ($a) => $extensionAmounts->get($a->agreement_id)->amount_centimes);
                } elseif ($effect['total'] !== null) {
                    $total += (int) $charges->get($record->id, collect())->where('id', '>', $effect['fuel_charge_revision'])->sum('amount_centimes');
                }
                $remaining += max(0, $total - $paid);
                $credit += max(0, $paid - $total);
                $pending += (int) $payments->reject(fn ($p) => in_array($p->id, $verified, true))->sum('amount_centimes');
            }
            $deposit = $deposits->get($record->id, collect());
            $verifiedReceipts = $deposit->where('action', 'caution_verified')->pluck('receipt_id')->all();
            $pending += (int) $deposit->where('action', 'caution_received')->filter(fn ($d) => $d->method === 'virement' && ! in_array($d->id, $verifiedReceipts, true))->sum('amount_centimes');
            $held = (int) $deposit->where('action', 'caution_received')->filter(fn ($d) => $d->method !== 'virement' || in_array($d->id, $verifiedReceipts, true))->sum('amount_centimes') - (int) $rows->whereIn('kind', ['deposit_return', 'deposit_allocate'])->sum('amount_centimes');
            $damage = \App\Support\Vehicles\DamageTickets::approvedDamageTotal($rows) - (int) $rows->where('kind', 'deposit_allocate')->where('scope', 'damage')->sum('amount_centimes');
            $remaining += $damage;
            $result[$record->id] = ['remaining_centimes' => $remaining, 'credit_centimes' => $credit, 'deposit_held_centimes' => $held, 'pending_centimes' => $pending, 'settled' => $remaining === 0 && $credit === 0 && $held === 0 && $pending === 0];
        }

        return $result;
    }

    public function summary(Reservation $record, CompanyMembership $actor): array
    {
        if (! $this->ready()) {
            return ['ready' => false];
        }
        $rows = $this->entries($record->id)->orderBy('id')->get();
        $departure = DB::table('reservation_departure_entries')->where('company_id', Tenant::id())->where('reservation_id', $record->id)->orderBy('id')->get();
        $verifiedIds = $departure->where('action', 'caution_verified')->pluck('receipt_id')->all();
        $receipts = $departure->where('action', 'caution_received')->map(function ($receipt) use ($rows, $verifiedIds): array {
            $verified = $receipt->method !== 'virement' || in_array($receipt->id, $verifiedIds, true);
            $used = $rows->where('deposit_receipt_id', $receipt->id);
            $returned = (int) $used->where('kind', 'deposit_return')->sum('amount_centimes');
            $allocated = (int) $used->where('kind', 'deposit_allocate')->sum('amount_centimes');

            return ['id' => $receipt->id, 'amount_centimes' => (int) $receipt->amount_centimes, 'method' => $receipt->method, 'verified' => $verified, 'returned_centimes' => $returned, 'allocated_centimes' => $allocated, 'remaining_centimes' => $verified ? (int) $receipt->amount_centimes - $returned - $allocated : 0];
        })->values();
        $inspection = DB::table('rental_return_inspections')->where('company_id', Tenant::id())->where('reservation_id', $record->id)->latest('id')->first();
        $rent = app(RentalPayment::class)->summary($record);
        $fuel = app(FuelSettlement::class)->summary($record, $actor);
        $approvals = $rows->where('kind', 'refund_approved')->map(fn ($row) => ['id' => $row->id, 'scope' => $row->scope, 'amount_centimes' => (int) $row->amount_centimes, 'remaining_centimes' => (int) $row->amount_centimes - (int) $rows->whereIn('kind', ['refund_paid', 'refund_cancelled'])->where('source_id', $row->id)->sum('amount_centimes'), 'reason' => $row->reason])->values()->all();
        $damage = \App\Support\Vehicles\DamageTickets::approvedDamageTotal($rows) - (int) $rows->where('kind', 'deposit_allocate')->where('scope', 'damage')->sum('amount_centimes');

        return ['ready' => true, 'revision' => $rows->last()?->id, 'can_manage' => (bool) $actor->is_primary_responsible, 'clean_return' => $record->status === 'retournee' && $inspection?->condition === 'bon' && $damage === 0, 'rent' => $rent, 'fuel' => $fuel, 'damage_remaining_centimes' => $damage, 'deposit' => ['receipts' => $receipts->all(), 'held_centimes' => (int) $receipts->sum('remaining_centimes'), 'returned_centimes' => (int) $receipts->sum('returned_centimes'), 'allocated_centimes' => (int) $receipts->sum('allocated_centimes')], 'refund_approvals' => $approvals, 'customer_remaining_centimes' => (int) ($rent['remaining_centimes'] ?? 0) + ($fuel['remaining_centimes'] ?? 0) + $damage, 'refund_due_centimes' => (int) collect($approvals)->sum('remaining_centimes'), 'entries' => $rows->map(fn ($row) => collect($row)->except(['company_id', 'request_hash', 'request_key'])->all())->all()];
    }

    public function perform(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($this->ready(), 503, 'La mise à jour des règlements doit être activée.');
        $kind = $input['kind'];
        abort_unless(in_array($kind, ['deposit_return', 'refund_paid'], true) || $actor->is_primary_responsible, 403, 'Cette décision est réservée au responsable.');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($id, $actor, $input, $kind, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = DB::table('rental_settlement_entries')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée avec des données différentes.');

                return true;
            }
            $summary = $this->summary($record, $actor);
            abort_unless((int) ($summary['revision'] ?? 0) === (int) ($input['expected_revision'] ?? 0), 409, 'Le règlement a changé. Actualisez avant de confirmer.');
            $scope = $input['scope'];
            $amount = (int) ($input['amount_centimes'] ?? 0);
            $data = ['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'kind' => $kind, 'scope' => $scope, 'amount_centimes' => $amount, 'occurred_on' => $input['occurred_on'], 'reason' => $input['reason'], 'reference' => $input['reference'] ?? null, 'method' => $input['method'] ?? null, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()];
            if ($kind === 'fuel_amount') {
                abort_unless($scope === 'fuel' && $summary['fuel']['charge'] && ($input['agreement_confirmed'] ?? false), 422, 'Confirmez le montant corrigé avec le client.');
            } elseif ($kind === 'rent_total') {
                abort_unless($scope === 'rent' && in_array($record->status, ['retournee', 'annulee'], true) && ($input['agreement_confirmed'] ?? false), 422, 'Cette régularisation suit un retour ou une annulation et un accord client.');
            } elseif ($kind === 'payment_void') {
                abort_unless(in_array($scope, ['rent', 'fuel'], true) && ($input['no_funds_received'] ?? false), 422, 'Une vraie réception se rembourse ; confirmez uniquement une écriture sans fonds reçus.');
                $column = $scope === 'rent' ? 'rent_payment_id' : 'fuel_payment_id';
                $table = $scope === 'rent' ? 'rental_payments' : 'rental_fuel_payments';
                $payment = DB::table($table)->where('company_id', Tenant::id())->where('reservation_id', $id)->where('id', $input['payment_id'] ?? 0)->first();
                abort_unless($payment, 404);
                abort_if($this->entries($id)->where('kind', 'payment_void')->where($column, $payment->id)->exists(), 409, 'Écriture déjà annulée.');
                abort_if($this->entries($id)->where('scope', $scope)->whereIn('kind', ['refund_paid', 'refund_approved'])->exists(), 422, 'Un remboursement lié existe. Faites examiner cette correction avant de poursuivre.');
                $data[$column] = $payment->id;
                $data['amount_centimes'] = (int) $payment->amount_centimes;
            } elseif (in_array($kind, ['deposit_return', 'deposit_allocate'], true)) {
                abort_unless(in_array($record->status, ['retournee', 'annulee'], true), 422, 'Enregistrez le retour ou l’annulation avant de solder la caution.');
                $receipt = collect($summary['deposit']['receipts'])->firstWhere('id', (int) ($input['deposit_receipt_id'] ?? 0));
                abort_unless($receipt && $amount > 0 && $amount <= $receipt['remaining_centimes'], 422, 'Le montant dépasse la caution vérifiée encore détenue.');
                $data['deposit_receipt_id'] = $receipt['id'];
                if ($kind === 'deposit_return') {
                    abort_unless($scope === 'deposit' && ($actor->is_primary_responsible || $summary['clean_return']), 403, 'Le retour de caution hors retour sain nécessite le responsable.');
                    abort_unless($receipt['method'] !== 'carte_bloquee' || $data['method'] === 'carte_bloquee', 422, 'Enregistrez la levée du blocage carte, sans remboursement en espèces.');
                    abort_unless($receipt['method'] === 'carte_bloquee' || in_array($data['method'], ['especes', 'virement', 'carte'], true), 422, 'Indiquez le moyen effectif du remboursement.');
                } else {
                    abort_unless(in_array($scope, ['rent', 'fuel', 'damage'], true) && $receipt['method'] !== 'carte_bloquee', 422, 'Un blocage carte doit être levé ; ce n’est pas un encaissement à affecter.');
                    $due = $scope === 'rent' ? $summary['rent']['remaining_centimes'] : ($scope === 'fuel' ? $summary['fuel']['receivable_centimes'] : $summary['damage_remaining_centimes']);
                    abort_unless($amount <= ($due ?? 0), 422, 'Cette affectation dépasse le montant dû.');
                    $data['method'] = null;
                }
            } elseif ($kind === 'refund_approved') {
                abort_unless(in_array($scope, ['rent', 'fuel'], true) && $amount > 0, 422);
                $financial = $scope === 'rent' ? $summary['rent'] : $summary['fuel'];
                $effects = $this->effects($id, $scope);
                abort_unless($amount <= max(0, ($financial['surplus_centimes'] ?? 0) - $effects['refund_reserved']), 422, 'Le montant dépasse le trop-perçu disponible, après les remboursements déjà approuvés.');
            } elseif (in_array($kind, ['refund_paid', 'refund_cancelled'], true)) {
                $approval = $this->entries($id)->where('kind', 'refund_approved')->where('id', $input['source_id'] ?? 0)->first();
                abort_unless($approval && $approval->scope === $scope, 404);
                $remaining = (int) $approval->amount_centimes - (int) $this->entries($id)->whereIn('kind', ['refund_paid', 'refund_cancelled'])->where('source_id', $approval->id)->sum('amount_centimes');
                abort_unless($amount > 0 && $amount <= $remaining, 422, 'Respectez le montant approuvé restant.');
                $financial = $scope === 'rent' ? $summary['rent'] : $summary['fuel'];
                if ($kind === 'refund_paid') {
                    abort_unless(in_array($data['method'], ['especes', 'virement', 'carte'], true), 422, 'Indiquez le moyen réel du remboursement.');
                    abort_unless($amount <= ($financial['surplus_centimes'] ?? 0), 422, 'Les fonds disponibles ont changé. Faites revoir l’approbation.');
                }
                $data['source_id'] = $approval->id;
            } else {
                abort(422, 'Cette décision nécessite son dossier de dommage et ses preuves.');
            }
            $data['snapshot'] = json_encode(['extension_revision' => app(RentalExtension::class)->ready() ? (int) DB::table('rental_extension_activations')->where('company_id', Tenant::id())->where('reservation_id', $id)->max('id') : 0, 'swap_revision' => app(RentalVehicleSwap::class)->ready() ? (int) DB::table('rental_vehicle_swaps')->where('company_id', Tenant::id())->where('reservation_id', $id)->max('id') : 0, 'fuel_charge_revision' => (int) DB::table('rental_fuel_charges')->where('company_id', Tenant::id())->where('reservation_id', $id)->max('id'), 'before' => ['rent_remaining_centimes' => $summary['rent']['remaining_centimes'], 'fuel_remaining_centimes' => $summary['fuel']['remaining_centimes'] ?? 0, 'deposit_held_centimes' => $summary['deposit']['held_centimes']], 'agreement_confirmed' => $input['agreement_confirmed'] ?? false, 'no_funds_received' => $input['no_funds_received'] ?? false]);
            $entry = DB::table('rental_settlement_entries')->insertGetId($data);
            app(RentalContract::class)->event($id, $actor->id, 'settlement_'.$kind, ['entry_id' => $entry, 'scope' => $scope, 'amount_centimes' => $data['amount_centimes'], 'reason' => $input['reason']]);

            return false;
        });
    }
}
