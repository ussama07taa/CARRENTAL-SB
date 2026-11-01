<?php

namespace App\Support\Finance;

use App\Support\Reservations\RentalSettlement;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

class ReportBalances
{
    /** Current balances, never a claimed historical month-end snapshot. */
    public function index(?int $vehicle, string $from, string $until): array
    {
        $company = Tenant::id();
        $today = now('Africa/Casablanca')->toDateString();
        $payables = app(PayablesOverview::class)->query();
        $sharedPayables = (clone $payables)->whereRaw('jsonb_array_length(allocations)>1');
        if ($vehicle) {
            $related = "EXISTS(SELECT 1 FROM jsonb_array_elements(allocations) a WHERE (a->>'vehicle_id')::bigint=?)";
            $payables->whereRaw($related, [$vehicle])->whereRaw('jsonb_array_length(allocations)=1');
            $sharedPayables->whereRaw($related, [$vehicle]);
        }
        $payTotals = (clone $payables)->selectRaw("COALESCE(SUM(balance_centimes) FILTER(WHERE cost_final),0) AS total_centimes,
            COALESCE(SUM(balance_centimes) FILTER(WHERE cost_final AND source IN ('manual','maintenance')),0) AS expenses_centimes,
            COALESCE(SUM(balance_centimes) FILTER(WHERE cost_final AND source='loan'),0) AS loans_centimes,
            COALESCE(SUM(balance_centimes) FILTER(WHERE cost_final AND source='purchase'),0) AS apport_centimes,
            COALESCE(SUM(balance_centimes) FILTER(WHERE cost_final AND due_on<?),0) AS overdue_centimes,
            COALESCE(SUM(balance_centimes) FILTER(WHERE cost_final AND due_on BETWEEN ? AND ?),0) AS month_due_centimes,
            COUNT(*) FILTER(WHERE NOT cost_final) AS provisional_count,COUNT(*) FILTER(WHERE cost_final AND due_on IS NULL) AS undated_count",
            [$today, $from, $until])->first();
        $payRows = (clone $payables)->where('cost_final', true)->orderByRaw('CASE WHEN due_on<=? THEN 0 WHEN due_on IS NULL THEN 1 ELSE 2 END', [$today])->orderByRaw('due_on ASC NULLS LAST')->orderBy('key')->limit(20)->get()->map(function ($r) {
            return ['key' => $r->key, 'source' => $r->source, 'source_id' => (int) $r->source_id, 'title' => $r->title, 'party' => $r->supplier, 'due_on' => $r->due_on, 'remaining_centimes' => (int) $r->balance_centimes,
                'vehicle' => $r->vehicle ? json_decode($r->vehicle, true) : null, 'shared' => count(json_decode($r->allocations, true) ?? []) > 1];
        })->all();
        $customerTotals = ['remaining_centimes' => 0, 'credit_centimes' => 0, 'deposit_held_centimes' => 0, 'pending_centimes' => 0, 'refund_due_centimes' => 0, 'count' => 0];
        $customerRows = [];
        $query = DB::table('reservations as r')->where('r.company_id', $company)
            ->join('customers as c', function ($j) {
                $j->on('c.id', '=', 'r.customer_id')->on('c.company_id', '=', 'r.company_id');
            })
            ->join('vehicles as v', function ($j) {
                $j->on('v.id', '=', 'r.vehicle_id')->on('v.company_id', '=', 'r.company_id');
            })
            ->selectRaw("r.*,concat(c.first_name,' ',c.last_name) AS customer,concat_ws(' ',v.make,v.model,v.plate) AS vehicle,
                EXISTS(SELECT 1 FROM rental_vehicle_swaps sw WHERE sw.company_id=r.company_id AND sw.reservation_id=r.id) AS shared,
                EXISTS(SELECT 1 FROM rental_settlement_entries se WHERE se.company_id=r.company_id AND se.reservation_id=r.id AND se.kind='rent_total') AS adjusted");
        if ($vehicle) {
            $query->where('r.vehicle_id', $vehicle)->whereNotExists(function ($q) use ($company) {
                $q->selectRaw('1')->from('rental_vehicle_swaps as sw')->where('sw.company_id', $company)->whereColumn('sw.reservation_id', 'r.id');
            });
        }
        $query->orderBy('r.id')->chunkById(200, function ($records) use (&$customerTotals, &$customerRows, $company) {
            $briefs = app(RentalSettlement::class)->briefs($records);
            $refunds = DB::table('rental_settlement_entries')->where('company_id', $company)->whereIn('reservation_id', $records->pluck('id'))
                ->selectRaw("reservation_id,COALESCE(SUM(CASE WHEN kind='refund_approved' THEN amount_centimes WHEN kind IN ('refund_paid','refund_cancelled') THEN -amount_centimes ELSE 0 END),0) AS remaining")->groupBy('reservation_id')->get()->keyBy('reservation_id');
            foreach ($records as $r) {
                $b = $briefs[$r->id] ?? ['remaining_centimes' => 0, 'credit_centimes' => 0, 'deposit_held_centimes' => 0, 'pending_centimes' => 0];
                $eligible = in_array($r->status, ['confirmee', 'en_location', 'retournee'], true) || $r->adjusted;
                $remaining = $eligible ? $b['remaining_centimes'] : 0;
                $customerTotals['remaining_centimes'] += $remaining;
                foreach (['credit_centimes', 'deposit_held_centimes', 'pending_centimes'] as $key) {
                    $customerTotals[$key] += $b[$key];
                }
                $customerTotals['refund_due_centimes'] += max(0, (int) ($refunds->get($r->id)?->remaining ?? 0));
                if ($remaining > 0) {
                    $customerTotals['count']++;
                    $customerRows[] = ['source' => 'rental', 'source_id' => (int) $r->id, 'title' => $r->customer, 'vehicle' => $r->vehicle, 'remaining_centimes' => $remaining, 'shared' => (bool) $r->shared];
                }
            }
        }, 'r.id', 'id');
        usort($customerRows, fn ($a, $b) => $b['remaining_centimes'] <=> $a['remaining_centimes']);
        $latest = DB::table('damage_entries')->where('company_id', $company)->where('kind', 'insurance')
            ->selectRaw('DISTINCT ON(ticket_id) ticket_id,payload')->orderBy('ticket_id')->orderByDesc('id');
        $received = DB::table('damage_entries as d')->where('d.company_id', $company)->where('d.kind', 'receipt')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('damage_entries as x')->whereColumn('x.company_id', 'd.company_id')->whereColumn('x.ticket_id', 'd.ticket_id')->where('x.kind', 'receipt_void')->whereRaw("(x.payload->>'entry_id')::bigint=d.id");
            })
            ->selectRaw("ticket_id,SUM((payload->>'amount_centimes')::bigint) AS received")->groupBy('ticket_id');
        $insurance = DB::query()->fromSub($latest, 'i')->join('damage_tickets as t', function ($j) use ($company) {
            $j->on('t.id', '=', 'i.ticket_id')->where('t.company_id', $company);
        })
            ->leftJoinSub($received, 'p', 'p.ticket_id', '=', 'i.ticket_id')->when($vehicle, fn ($q) => $q->where('t.vehicle_id', $vehicle))
            ->selectRaw("COALESCE(SUM(GREATEST(0,(i.payload->>'approved_centimes')::bigint-COALESCE(p.received,0))),0) AS approved_remaining_centimes,
                COUNT(*) FILTER(WHERE (i.payload->>'approved_centimes') IS NULL) AS pending_count")->first();
        $creditQ = app(ExpenseReadModel::class)->query()->where('credit_centimes', '>', 0);
        if ($vehicle) {
            $creditQ->whereRaw("jsonb_array_length(allocations)=1 AND (allocations->0->>'vehicle_id')::bigint=?", [$vehicle]);
        }

        return ['as_of' => $today, 'scope' => 'current_all_dates', 'customers' => $customerTotals, 'customer_rows' => array_slice($customerRows, 0, 20),
            'payables' => array_map('intval', (array) $payTotals), 'payable_rows' => $payRows,
            'payable_count' => (clone $payables)->where('cost_final', true)->count(),
            'shared_payable_centimes' => (int) $sharedPayables->where('cost_final', true)->sum('balance_centimes'),
            'supplier_credit_centimes' => (int) $creditQ->sum('credit_centimes'),
            'insurance' => array_map('intval', (array) $insurance)];
    }
}
