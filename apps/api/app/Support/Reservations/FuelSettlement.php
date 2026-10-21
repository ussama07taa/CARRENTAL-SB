<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FuelSettlement
{
    private function table(string $name): Builder
    {
        return DB::table($name)->where('company_id', Tenant::id());
    }

    public function summary(Reservation $record, CompanyMembership $actor): array
    {
        if (! Schema::hasTable('rental_fuel_charges')) {
            return ['ready' => false];
        }
        $charges = $this->table('rental_fuel_charges')->where('reservation_id', $record->id)->orderBy('id')->get();
        $charge = $charges->last();
        $return = app(RentalVehicleSwap::class)->returned($record);
        $currentCharge = $return ? $charges->firstWhere('return_id', $return->id) : null;
        $inspection = $return ? $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first() : null;
        $snapshot = $inspection ? json_decode($inspection->snapshot, true) : null;
        $effects = app(RentalSettlement::class)->effects($record->id, 'fuel');
        $payments = $charge ? $this->table('rental_fuel_payments')->where('reservation_id', $record->id)->orderBy('id')->get() : collect();
        $checks = $charge ? $this->table('rental_fuel_verifications')->where('reservation_id', $record->id)->get()->keyBy('payment_id') : collect();
        $active = $payments->reject(fn ($p) => in_array($p->id, $effects['void_ids'], true));
        $verified = (int) $active->filter(fn ($p) => $checks->has($p->id))->sum('amount_centimes') + $effects['allocated'] - $effects['refunded'];
        $pending = (int) $active->reject(fn ($p) => $checks->has($p->id))->sum('amount_centimes');
        $total = $effects['total'] === null ? (int) $charges->sum('amount_centimes') : (int) $effects['total'] + (int) $charges->where('id', '>', $effects['fuel_charge_revision'])->sum('amount_centimes');

        return ['ready' => true, 'can_manage' => (bool) $actor->is_primary_responsible, 'can_charge' => $record->status === 'retournee' && ! $currentCharge && $inspection && ($snapshot['fuel_difference_eighths'] ?? 0) < 0, 'inspection_id' => $inspection?->id, 'charge' => $charge ? ['id' => $charge->id, 'amount_centimes' => $total, 'ticket_reference' => $charge->ticket_reference, 'reason' => $charge->reason, 'has_ticket' => $charge->ticket_path !== null, 'snapshot' => json_decode($charge->snapshot, true), 'inspection_changed' => $currentCharge && $currentCharge->inspection_id !== $inspection?->id] : null, 'verified_centimes' => $verified, 'pending_centimes' => $pending, 'surplus_centimes' => max(0, $verified - $total), 'remaining_centimes' => max(0, $total - $verified), 'receivable_centimes' => max(0, $total - $verified - $pending), 'payments' => $payments->map(fn ($p) => ['id' => $p->id, 'amount_centimes' => (int) $p->amount_centimes, 'method' => $p->method, 'received_on' => $p->received_on, 'reference' => $p->reference, 'reason' => $p->reason, 'status' => in_array($p->id, $effects['void_ids'], true) ? 'void' : ($checks->has($p->id) ? 'verified' : 'pending')])->values()->all()];
    }

    public function perform(int $id, CompanyMembership $actor, string $operation, array $input, ?UploadedFile $ticket = null, ?int $paymentId = null): bool
    {
        abort_unless($actor->is_primary_responsible, 403, 'Cette action est réservée au responsable.');
        abort_unless(Schema::hasTable('rental_fuel_charges'), 503, 'La régularisation attend l’activation de la mise à jour.');
        $hash = hash('sha256', json_encode([$id, $operation, $paymentId, $input, $ticket ? hash_file('sha256', $ticket->getRealPath()) : null]));
        $written = null;
        try {
            return DB::transaction(function () use ($id, $actor, $operation, $input, $ticket, $paymentId, $hash, &$written): bool {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $record = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
                $table = ['charge' => 'rental_fuel_charges', 'payment' => 'rental_fuel_payments', 'verify' => 'rental_fuel_verifications'][$operation];
                $old = $this->table($table)->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à des données différentes.');

                    return true;
                }
                abort_unless($record->status === 'retournee' || ($operation !== 'charge' && $this->table('rental_fuel_charges')->where('reservation_id', $id)->exists()), 422, 'Enregistrez le retour avant la régularisation.');
                $base = ['company_id' => Tenant::id(), 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'reason' => $input['reason'], 'created_at' => now()];
                if ($operation === 'charge') {
                    $return = app(RentalVehicleSwap::class)->returned($record);
                    abort_unless($return, 422, 'Enregistrez le retour de cette voiture.');
                    abort_if($this->table($table)->where('return_id', $return->id)->exists(), 409, 'Une régularisation existe déjà pour ce retour. Actualisez le dossier.');
                    $inspection = $this->table('rental_return_inspections')->where('return_id', $return->id)->latest('id')->first();
                    abort_unless($inspection && $inspection->id === (int) $input['inspection_id'], 409, 'Le contrôle a changé. Actualisez avant de convenir du montant.');
                    $snapshot = json_decode($inspection->snapshot, true);
                    abort_unless($snapshot['fuel_difference_eighths'] < 0, 422, 'Aucun manque de carburant constaté sur ce contrôle.');
                    $file = [];
                    if ($ticket) {
                        $written = Tenant::id().'/'.$id.'/fuel/'.Str::uuid().'.'.$ticket->extension();
                        Storage::disk('rental_returns')->put($written, file_get_contents($ticket->getRealPath()));
                        $file = ['ticket_path' => $written, 'ticket_mime' => $ticket->getMimeType(), 'ticket_size' => $ticket->getSize(), 'ticket_sha256' => hash_file('sha256', $ticket->getRealPath())];
                    }
                    $chargeId = DB::table($table)->insertGetId([...$base, 'return_id' => $return->id, 'inspection_id' => $inspection->id, 'amount_centimes' => $input['amount_centimes'], 'ticket_reference' => $input['ticket_reference'], 'snapshot' => json_encode(['departure_fuel_eighths' => $snapshot['departure']['fuel_eighths'], 'return_fuel_eighths' => $inspection->fuel_eighths, 'fuel_difference_eighths' => $snapshot['fuel_difference_eighths'], 'agreement_confirmed' => true, 'rental_total_centimes' => $record->frozen_total_centimes]), ...$file]);
                    app(RentalContract::class)->event($id, $actor->id, 'fuel_charge_agreed', ['charge_id' => $chargeId, 'amount_centimes' => (int) $input['amount_centimes'], 'reason' => $input['reason'], 'inspection_id' => $inspection->id]);
                } else {
                    $charge = $this->table('rental_fuel_charges')->where('reservation_id', $id)->latest('id')->firstOrFail();
                    if ($operation === 'payment') {
                        $summary = $this->summary($record, $actor);
                        abort_unless((int) $input['amount_centimes'] <= $summary['receivable_centimes'], 422, 'Ce montant dépasse le solde non couvert. Les virements en attente restent réservés à cette régularisation.');
                        $payment = DB::table($table)->insertGetId([...$base, 'charge_id' => $charge->id, ...collect($input)->only(['amount_centimes', 'method', 'received_on', 'reference'])->all()]);
                        if ($input['method'] !== 'virement') {
                            DB::table('rental_fuel_verifications')->insert([...$base, 'charge_id' => $charge->id, 'payment_id' => $payment, 'reason' => 'Réception effective attestée lors de l’enregistrement.']);
                        }
                        app(RentalContract::class)->event($id, $actor->id, 'fuel_payment_recorded', ['charge_id' => $charge->id, 'payment_id' => $payment, 'amount_centimes' => (int) $input['amount_centimes'], 'method' => $input['method'], 'verified' => $input['method'] !== 'virement']);
                    } else {
                        $payment = $this->table('rental_fuel_payments')->where('reservation_id', $id)->where('id', $paymentId)->firstOrFail();
                        $charge = $this->table('rental_fuel_charges')->where('reservation_id', $id)->where('id', $payment->charge_id)->firstOrFail();
                        abort_if(in_array($paymentId, app(RentalSettlement::class)->effects($id, 'fuel')['void_ids'], true), 422, 'Cette écriture a été annulée.');
                        abort_unless($payment->method === 'virement' && ! $this->table($table)->where('payment_id', $paymentId)->exists(), 422, 'Ce paiement est déjà vérifié.');
                        DB::table($table)->insert([...$base, 'charge_id' => $charge->id, 'payment_id' => $paymentId]);
                        app(RentalContract::class)->event($id, $actor->id, 'fuel_transfer_verified', ['charge_id' => $charge->id, 'payment_id' => $paymentId, 'amount_centimes' => (int) $payment->amount_centimes, 'reason' => $input['reason']]);
                    }
                }

                return false;
            });
        } catch (\Throwable $e) {
            if ($written) {
                Storage::disk('rental_returns')->delete($written);
            }
            throw $e;
        }
    }
}
