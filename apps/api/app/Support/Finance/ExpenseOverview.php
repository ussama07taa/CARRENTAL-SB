<?php

namespace App\Support\Finance;

use App\Models\Vehicle;
use Illuminate\Database\Query\Builder;

class ExpenseOverview
{
    public function index(array $input): array
    {
        $today = now('Africa/Casablanca')->toDateString();
        $model = app(ExpenseReadModel::class);
        $query = $model->query(true);
        if ($search = trim($input['q'] ?? '')) {
            $query->whereRaw("strpos(lower(concat_ws(' ',title,supplier,invoice_reference,allocations::text,key)),lower(?)) > 0", [$search]);
        }
        if (! empty($input['vehicle_id'])) {
            $query->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(allocations) a WHERE (a->>'vehicle_id')::bigint=?)", [(int) $input['vehicle_id']]);
        }
        if (! empty($input['from'])) {
            $query->where('date', '>=', $input['from']);
        }
        if (! empty($input['to'])) {
            $query->where('date', '<=', $input['to']);
        }
        if (! empty($input['category'])) {
            $query->where('category', $input['category']);
        }
        if (($input['document'] ?? '') === 'missing') {
            $query->where('has_document', false);
        } elseif (($input['document'] ?? '') === 'attached') {
            $query->where('has_document', true);
        }
        $counts = (array) (clone $query)->selectRaw("COUNT(*) AS all,COUNT(*) FILTER (WHERE source='maintenance') AS maintenance,COUNT(*) FILTER (WHERE source='manual') AS manual,COUNT(*) FILTER (WHERE balance_centimes>0) AS unpaid,COUNT(*) FILTER (WHERE cost_final AND NOT cancelled AND balance_centimes=0 AND credit_centimes=0) AS paid,COUNT(*) FILTER (WHERE NOT cost_final AND NOT cancelled) AS pricing,COUNT(*) FILTER (WHERE credit_centimes>0) AS credit,COUNT(*) FILTER (WHERE cancelled) AS cancelled")->first();
        $counts = array_map('intval', $counts);
        $this->filter($query, $input['tab'] ?? 'all');
        $summaryQuery = clone $query;
        foreach (['confirmed_cost_centimes', 'gross_paid_centimes', 'refunded_centimes', 'paid_centimes', 'balance_centimes', 'credit_centimes'] as $column) {
            $summaryQuery->selectRaw("COALESCE(SUM($column),0) AS $column");
        }
        $summaryQuery->selectRaw('COUNT(*) FILTER (WHERE NOT cost_final AND NOT cancelled) AS provisional_files');
        $summary = array_map('intval', (array) $summaryQuery->first());
        $vehicle = (int) ($input['vehicle_id'] ?? 0);
        $summary['vehicle_cost_centimes'] = $vehicle ? (int) (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN cancelled THEN 0 WHEN source='maintenance' THEN confirmed_cost_centimes ELSE (SELECT SUM((a->>'amount_centimes')::bigint) FROM jsonb_array_elements(allocations) a WHERE (a->>'vehicle_id')::bigint=?) END),0) AS part", [$vehicle])->value('part') : null;
        $total = (clone $query)->count();
        $last = max(1, (int) ceil($total / 20));
        $page = min((int) ($input['page'] ?? 1), $last);
        $rows = $query->orderByDesc('date')->orderByDesc('key')->offset(($page - 1) * 20)->limit(20)->get()->map(function ($row) use ($model, $today, $vehicle) {
            $r = $model->row($row);
            $r['state'] = $r['credit_centimes'] > 0 ? ($r['cost_final'] ? 'credit' : 'advance') : ($r['cancelled'] ? 'cancelled' : (! $r['cost_final'] ? 'pricing' : ($r['balance_centimes'] === 0 ? 'paid' : ($r['due_on'] && $r['due_on'] < $today ? 'overdue' : ($r['paid_centimes'] > 0 ? 'partial' : 'unpaid')))));
            $r['vehicle_cost_centimes'] = $vehicle ? ($r['cancelled'] ? 0 : (int) collect($r['allocations'])->where('vehicle_id', $vehicle)->sum('amount_centimes')) : null;

            return $r;
        })->all();

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'last_page' => $last, 'counts' => $counts, 'summary' => $summary, 'today' => $today, 'vehicles' => Vehicle::orderBy('make')->get(['id', 'make', 'model', 'plate']), 'categories' => Expenses::CATEGORIES];
    }

    private function filter(Builder $query, string $tab): void
    {
        match ($tab) {
            'maintenance','manual' => $query->where('source', $tab),
            'unpaid' => $query->where('balance_centimes', '>', 0),
            'paid' => $query->where('cost_final', true)->where('cancelled', false)->where('balance_centimes', 0)->where('credit_centimes', 0),
            'pricing' => $query->where('cost_final', false)->where('cancelled', false),
            'credit' => $query->where('credit_centimes', '>', 0),
            'cancelled' => $query->where('cancelled', true),
            default => null,
        };
    }
}
