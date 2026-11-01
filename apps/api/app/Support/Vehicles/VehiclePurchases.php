<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VehiclePurchases
{
    public function available(): bool
    {
        return Schema::hasTable('vehicle_purchases');
    }

    public function currentPayments(): Builder
    {
        $latest = DB::table('vehicle_purchase_payments')->where('company_id', Tenant::id())
            ->selectRaw('DISTINCT ON (payment_key) *')->orderBy('payment_key')->orderByDesc('version');

        return DB::query()->fromSub($latest, 'pp');
    }

    public function summaries(): Builder
    {
        $paid = $this->currentPayments()->selectRaw('purchase_id, SUM(amount_centimes) AS paid_centimes')->groupBy('purchase_id');

        return DB::table('vehicle_purchases as vp')->leftJoinSub($paid, 'paid', 'paid.purchase_id', '=', 'vp.id')
            ->where('vp.company_id', Tenant::id())->select('vp.*')
            ->selectRaw('COALESCE(paid.paid_centimes,0) AS paid_centimes, vp.down_payment_centimes-COALESCE(paid.paid_centimes,0) AS remaining_centimes');
    }

    public function create(object $loan, int $actor, array $input): void
    {
        abort_unless($this->available(), 503, 'Le suivi des achats attend son activation.');
        if ($this->summaries()->join('vehicle_loans as l', 'l.id', '=', 'vp.loan_id')->where('l.company_id', Tenant::id())
            ->where('l.vehicle_id', $loan->vehicle_id)->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('vehicle_loan_cancellations as c')->whereColumn('c.company_id', 'l.company_id')->whereColumn('c.loan_id', 'l.id');
            })->exists()) {
            throw ValidationException::withMessages(['vehicle_id' => 'Un achat est déjà enregistré pour cette voiture. Ouvrez son dossier existant.']);
        }
        $id = DB::table('vehicle_purchases')->insertGetId([
            'company_id' => Tenant::id(), 'loan_id' => $loan->id, 'actor_membership_id' => $actor,
            'seller' => trim($input['seller']), 'purchased_on' => $input['purchased_on'],
            'down_payment_due_on' => $input['down_payment_due_on'], 'price_centimes' => (int) $input['price_centimes'],
            'down_payment_centimes' => (int) $input['down_payment_centimes'], 'created_at' => now(),
        ]);
        app(VehicleLoans::class)->audit($loan, $actor, 'vehicle_purchase_recorded', ['purchase_id' => $id, ...$input]);
    }

    public function detail(int $loan): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $row = $this->summaries()->where('vp.loan_id', $loan)->first();
        if (! $row) {
            return null;
        }
        $service = app(VehicleLoans::class);
        $history = DB::table('vehicle_purchase_payments as p')->join('company_memberships as m', function ($j) {
            $j->on('m.id', '=', 'p.actor_membership_id')->on('m.company_id', '=', 'p.company_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('p.company_id', Tenant::id())
            ->where('p.purchase_id', $row->id)->orderBy('p.id')->get(['p.*', 'u.name as actor'])
            ->map(function ($r) use ($service) {
                unset($r->request_hash, $r->request_key, $r->actor_membership_id, $r->company_id);

                return $service->numbers($r);
            });

        return ['data' => $service->numbers($row), 'payments' => $this->currentPayments()->where('purchase_id', $row->id)->orderByDesc('id')->get()->map(fn ($r) => $service->numbers((object) [
            'id' => $r->id, 'payment_key' => $r->payment_key, 'version' => $r->version,
            'amount_centimes' => $r->amount_centimes, 'paid_on' => $r->paid_on,
            'method' => $r->method, 'reference' => $r->reference,
        ])), 'history' => $history];
    }

    public function save(object $loan, int $actor, array $input, ?int $payment): int
    {
        abort_unless($this->available(), 503, 'Le suivi des achats attend son activation.');
        $service = app(VehicleLoans::class);
        $purchase = $this->summaries()->where('vp.loan_id', $loan->id)->firstOrFail();
        $hash = $service->hash([...$input, 'loan_id' => $loan->id, 'payment_id' => $payment]);
        if ($old = $service->replay('vehicle_purchase_payments', $input['request_key'], $hash)) {
            return $old->id;
        }
        $service->ensureActive($loan->id);
        $current = null;
        if ($payment !== null) {
            $original = $service->query('vehicle_purchase_payments')->where('purchase_id', $purchase->id)->where('id', $payment)->firstOrFail();
            $current = $this->currentPayments()->where('purchase_id', $purchase->id)->where('payment_key', $original->payment_key)->firstOrFail();
            abort_if((int) $current->version !== (int) ($input['expected_version'] ?? 0), 409, 'Cet apport a changé. Actualisez le dossier.');
            if (trim($input['reason'] ?? '') === '') {
                throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la correction.']);
            }
        } elseif ((int) $input['amount_centimes'] === 0) {
            throw ValidationException::withMessages(['amount_centimes' => 'Indiquez le montant réellement versé.']);
        }
        abort_if((int) $purchase->remaining_centimes !== (int) $input['expected_remaining_centimes'], 409, 'Le reste de l’apport a changé. Actualisez le dossier.');
        $available = (int) $purchase->remaining_centimes + (int) ($current->amount_centimes ?? 0);
        if ((int) $input['amount_centimes'] > $available) {
            throw ValidationException::withMessages(['amount_centimes' => 'Ce montant dépasse l’apport restant à régler.']);
        }
        if ($input['paid_on'] < $loan->tracking_from || $input['paid_on'] < $purchase->purchased_on) {
            throw ValidationException::withMessages(['paid_on' => 'La date doit être au moins celle de l’achat et du début du suivi.']);
        }
        $id = DB::table('vehicle_purchase_payments')->insertGetId([
            'company_id' => Tenant::id(), 'purchase_id' => $purchase->id, 'actor_membership_id' => $actor,
            'payment_key' => $current->payment_key ?? $input['request_key'], 'version' => $current ? $current->version + 1 : 1,
            'amount_centimes' => (int) $input['amount_centimes'], 'paid_on' => $input['paid_on'], 'method' => $input['method'],
            'reference' => $input['reference'] ?? null, 'reason' => $input['reason'] ?? null,
            'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now(),
        ]);
        $service->audit($loan, $actor, $current ? 'purchase_payment_corrected' : 'purchase_payment_recorded', [
            'payment_id' => $id, 'before_centimes' => $current ? (int) $current->amount_centimes : null,
            'amount_centimes' => (int) $input['amount_centimes'], 'reason' => $input['reason'] ?? null,
        ]);

        return $id;
    }
}
