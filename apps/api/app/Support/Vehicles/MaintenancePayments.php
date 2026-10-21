<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaintenancePayments
{
    public function query(): Builder
    {
        return DB::table('maintenance_payments as p')->leftJoin('maintenance_payment_cancellations as x', function ($j) {
            $j->on('x.company_id', '=', 'p.company_id')->on('x.payment_key', '=', 'p.request_key');
        })->where('p.company_id', Tenant::id())->select('p.*', 'x.id as cancellation_id')->selectRaw('COALESCE((SELECT SUM(a.amount_centimes) FROM maintenance_refund_allocations a WHERE a.company_id=p.company_id AND a.payment_key=p.request_key AND NOT EXISTS (SELECT 1 FROM maintenance_refund_cancellations rx WHERE rx.company_id=a.company_id AND rx.refund_id=a.refund_id)), 0) AS refunded_centimes')->selectRaw('CASE WHEN x.id IS NOT NULL THEN 0 ELSE COALESCE((SELECT c.amount_centimes FROM maintenance_payment_corrections c WHERE c.company_id=p.company_id AND c.payment_key=p.request_key ORDER BY c.version DESC LIMIT 1), p.amount_centimes) END AS effective_amount_centimes')->selectRaw('COALESCE((SELECT c.id FROM maintenance_payment_corrections c WHERE c.company_id=p.company_id AND c.payment_key=p.request_key ORDER BY c.version DESC LIMIT 1), 0) AS correction_revision');
    }

    public function cash(array $ids): Collection
    {
        return DB::query()->fromSub($this->query()->whereIn('p.maintenance_id', $ids), 'effective')->selectRaw('maintenance_id, SUM(effective_amount_centimes) AS gross_centimes, SUM(refunded_centimes) AS refunded_centimes, SUM(effective_amount_centimes - refunded_centimes) AS net_centimes')->groupBy('maintenance_id')->get()->keyBy('maintenance_id');
    }

    public function totals(array $ids): Collection
    {
        return $this->cash($ids)->pluck('net_centimes', 'maintenance_id');
    }

    public function refunds(int $entry): Collection
    {
        $rows = DB::table('maintenance_refunds as r')->join('company_memberships as m', function ($j) {
            $j->on('r.company_id', '=', 'm.company_id')->on('r.actor_membership_id', '=', 'm.id');
        })->join('users as u', 'm.user_id', '=', 'u.id')->where('r.company_id', Tenant::id())->where('r.maintenance_id', $entry)->orderByDesc('r.id')->get(['r.id', 'r.amount_centimes', 'r.received_on', 'r.method', 'r.reason', 'r.reference', 'r.mime', 'r.created_at', 'u.name as actor']);
        $allocations = DB::table('maintenance_refund_allocations as a')->join('maintenance_payments as p', function ($j) {
            $j->on('a.company_id', '=', 'p.company_id')->on('a.payment_key', '=', 'p.request_key');
        })->where('a.company_id', Tenant::id())->whereIn('a.refund_id', $rows->pluck('id'))->orderBy('a.id')->get(['a.refund_id', 'p.id as payment_id', 'a.amount_centimes'])->groupBy('refund_id');

        $cancellations = DB::table('maintenance_refund_cancellations as x')->join('company_memberships as m', function ($j) {
            $j->on('x.company_id', '=', 'm.company_id')->on('x.actor_membership_id', '=', 'm.id');
        })->join('users as u', 'm.user_id', '=', 'u.id')->where('x.company_id', Tenant::id())->whereIn('x.refund_id', $rows->pluck('id'))->get(['x.id', 'x.refund_id', 'x.before_centimes', 'x.reason', 'x.created_at', 'u.name as actor'])->keyBy('refund_id');

        return $rows->map(function ($row) use ($allocations, $cancellations) {
            $row->cancellation = $cancellations[$row->id] ?? null;
            if ($row->cancellation) {
                unset($row->cancellation->refund_id);
            }
            $row->has_file = $row->mime !== null;
            $row->allocations = ($allocations[$row->id] ?? collect())->map(fn ($a) => ['payment_id' => $a->payment_id, 'amount_centimes' => (int) $a->amount_centimes])->values();

            return $row;
        });
    }

    public function rows(int $entry): Collection
    {
        $rows = $this->query()->where('p.maintenance_id', $entry)->orderBy('p.id')->get();
        $history = DB::table('maintenance_payment_corrections as c')->join('company_memberships as m', function ($j) {
            $j->on('c.company_id', '=', 'm.company_id')->on('c.actor_membership_id', '=', 'm.id');
        })->join('users as u', 'm.user_id', '=', 'u.id')->where('c.company_id', Tenant::id())->whereIn('c.payment_key', $rows->pluck('request_key'))->orderBy('c.id')->get(['c.id', 'c.payment_key', 'c.version', 'c.before_centimes', 'c.amount_centimes', 'c.reason', 'c.created_at', 'u.name as actor'])->groupBy('payment_key');
        $actors = DB::table('company_memberships as m')->join('users as u', 'm.user_id', '=', 'u.id')->where('m.company_id', Tenant::id())->whereIn('m.id', $rows->pluck('actor_membership_id'))->pluck('u.name', 'm.id');

        $cancellations = DB::table('maintenance_payment_cancellations as x')->join('company_memberships as m', function ($j) {
            $j->on('x.company_id', '=', 'm.company_id')->on('x.actor_membership_id', '=', 'm.id');
        })->join('users as u', 'm.user_id', '=', 'u.id')->where('x.company_id', Tenant::id())->whereIn('x.payment_key', $rows->pluck('request_key'))->get(['x.id', 'x.payment_key', 'x.before_centimes', 'x.reason', 'x.created_at', 'u.name as actor'])->keyBy('payment_key');

        return $rows->map(function ($p) use ($history, $actors, $cancellations) {
            $cancellation = $cancellations[$p->request_key] ?? null;
            if ($cancellation) {
                unset($cancellation->payment_key);
            }

            return ['id' => $p->id, 'cancellation' => $cancellation, 'actor' => $actors[$p->actor_membership_id] ?? null, 'amount_centimes' => (int) $p->effective_amount_centimes, 'refunded_centimes' => (int) $p->refunded_centimes, 'available_centimes' => (int) $p->effective_amount_centimes - (int) $p->refunded_centimes, 'original_amount_centimes' => (int) $p->amount_centimes, 'correction_revision' => (int) $p->correction_revision, 'paid_on' => $p->paid_on, 'method' => $p->method, 'reference' => $p->reference, 'created_at' => $p->created_at, 'corrections' => ($history[$p->request_key] ?? collect())->map(function ($c) {
                unset($c->payment_key);

                return $c;
            })->values()];
        });
    }
}
