<?php

namespace App\Support\Finance;

use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleLoans;
use App\Support\Vehicles\VehiclePurchases;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PayablesOverview
{
    public function query(): Builder
    {
        $expenses = app(ExpenseReadModel::class)->query()->where('balance_centimes', '>', 0)->where('cancelled', false)
            ->selectRaw('key,source,id AS source_id,NULL::bigint AS installment_id,NULL::integer AS position,title,supplier,invoice_reference AS reference,date,due_on,cost_centimes AS amount_centimes,paid_centimes,paid_centimes AS settled_centimes,0::bigint AS opening_paid_centimes,balance_centimes,cost_final,allocations,vehicle,odometer');
        $loans = DB::query()->fromSub(app(VehicleLoans::class)->installments(), 's')
            ->join('vehicle_loans as l', 'l.id', '=', 's.loan_id')
            ->join('vehicles as v', function ($j) {
                $j->on('v.id', '=', 'l.vehicle_id')->on('v.company_id', '=', 'l.company_id');
            })->where('l.company_id', Tenant::id())->where('s.remaining_centimes', '>', 0)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('vehicle_loan_cancellations as c')->whereColumn('c.loan_id', 'l.id')->whereColumn('c.company_id', 'l.company_id');
            })->selectRaw("'loan-'||s.id AS key,'loan' AS source,l.id AS source_id,s.id AS installment_id,s.position,'Échéance n° '||s.position AS title,l.lender AS supplier,l.reference,s.due_on AS date,s.due_on,s.amount_centimes,s.recorded_paid_centimes AS paid_centimes,s.settled_centimes,s.opening_paid_centimes,s.remaining_centimes AS balance_centimes,true AS cost_final,
             jsonb_build_array(jsonb_build_object('vehicle_id',v.id,'vehicle',jsonb_build_object('id',v.id,'make',v.make,'model',v.model,'plate',v.plate))) AS allocations,
             jsonb_build_object('id',v.id,'make',v.make,'model',v.model,'plate',v.plate) AS vehicle,NULL::jsonb AS odometer");

        $all = $expenses->unionAll($loans);
        if (app(VehiclePurchases::class)->available()) {
            $purchases = app(VehiclePurchases::class)->summaries()
                ->join('vehicle_loans as l', 'l.id', '=', 'vp.loan_id')
                ->join('vehicles as v', function ($j) {
                    $j->on('v.id', '=', 'l.vehicle_id')->on('v.company_id', '=', 'l.company_id');
                })->where('l.company_id', Tenant::id())->whereRaw('vp.down_payment_centimes-COALESCE(paid.paid_centimes,0)>0')
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('vehicle_loan_cancellations as c')->whereColumn('c.loan_id', 'l.id')->whereColumn('c.company_id', 'l.company_id');
                })->select([])->selectRaw("'purchase-'||vp.id AS key,'purchase' AS source,l.id AS source_id,NULL::bigint AS installment_id,NULL::integer AS position,'Apport initial · achat voiture' AS title,vp.seller AS supplier,l.reference,vp.purchased_on AS date,vp.down_payment_due_on AS due_on,vp.down_payment_centimes AS amount_centimes,COALESCE(paid.paid_centimes,0) AS paid_centimes,COALESCE(paid.paid_centimes,0) AS settled_centimes,0::bigint AS opening_paid_centimes,vp.down_payment_centimes-COALESCE(paid.paid_centimes,0) AS balance_centimes,true AS cost_final,
                 jsonb_build_array(jsonb_build_object('vehicle_id',v.id,'vehicle',jsonb_build_object('id',v.id,'make',v.make,'model',v.model,'plate',v.plate))) AS allocations,jsonb_build_object('id',v.id,'make',v.make,'model',v.model,'plate',v.plate) AS vehicle,NULL::jsonb AS odometer");
            $all->unionAll($purchases);
        }

        return DB::query()->fromSub($all, 'payables');
    }

    public function index(array $input): array
    {
        $today = now('Africa/Casablanca')->toDateString();
        $until = now('Africa/Casablanca')->addDays(30)->toDateString();
        $query = $this->query();
        if ($q = trim($input['q'] ?? '')) {
            $query->whereRaw("strpos(lower(concat_ws(' ',title,supplier,reference,allocations::text,key)),lower(?))>0", [$q]);
        }
        if (! empty($input['vehicle_id'])) {
            $query->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(allocations) a WHERE (a->>'vehicle_id')::bigint=?)", [(int) $input['vehicle_id']]);
        }
        $window = $input['window'] ?? '30days';
        if ($window === 'overdue') {
            $query->where('due_on', '<', $today);
        } elseif ($window === '30days') {
            $query->where(fn ($q) => $q->whereNull('due_on')->orWhere('due_on', '<=', $until));
        }
        if (($input['source'] ?? 'all') === 'loan') {
            $query->where('source', 'loan');
        } elseif (($input['source'] ?? 'all') === 'expenses') {
            $query->whereIn('source', ['manual', 'maintenance']);
        } elseif (($input['source'] ?? 'all') === 'purchase') {
            $query->where('source', 'purchase');
        }
        $summary = (array) (clone $query)->selectRaw("COALESCE(SUM(balance_centimes) FILTER (WHERE source IN ('manual','maintenance')),0) AS expense_balance_centimes,COALESCE(SUM(balance_centimes) FILTER (WHERE source='loan'),0) AS loan_balance_centimes,COALESCE(SUM(balance_centimes) FILTER (WHERE source='purchase'),0) AS purchase_balance_centimes,COALESCE(SUM(balance_centimes),0) AS total_balance_centimes,COUNT(*) FILTER (WHERE NOT cost_final) AS provisional_count,COUNT(*) FILTER (WHERE due_on IS NULL) AS undated_count")->first();
        $summary = array_map('intval', $summary);
        $total = (clone $query)->count();
        $last = max(1, (int) ceil($total / 20));
        $page = min((int) ($input['page'] ?? 1), $last);
        $rows = $query->orderByRaw('due_on ASC NULLS LAST')->orderBy('key')->offset(($page - 1) * 20)->limit(20)->get()->map(function ($row) use ($today) {
            $r = (array) $row;
            foreach (['source_id', 'installment_id', 'position', 'amount_centimes', 'paid_centimes', 'settled_centimes', 'opening_paid_centimes', 'balance_centimes'] as $key) {
                $r[$key] = $r[$key] === null ? null : (int) $r[$key];
            }
            foreach (['vehicle', 'allocations', 'odometer'] as $key) {
                $r[$key] = $r[$key] === null ? null : json_decode($r[$key], true);
            }
            $r['state'] = ! $r['cost_final'] ? 'pricing' : ($r['due_on'] === null ? 'undated' : ($r['due_on'] < $today ? 'overdue' : ($r['due_on'] === $today ? 'due' : 'upcoming')));

            return $r;
        })->all();

        return ['data' => $rows, 'summary' => $summary, 'today' => $today, 'until' => $until, 'window' => $window, 'total' => $total, 'page' => $page, 'last_page' => $last];
    }
}
