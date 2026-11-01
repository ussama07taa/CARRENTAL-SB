<?php

namespace App\Support\Finance;

use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinancialReports
{
    public const CATEGORIES = [
        'rent' => 'Loyers encaissés', 'fuel' => 'Carburant encaissé', 'insurance' => 'Indemnités assurance',
        'maintenance' => 'Entretien & réparations', 'expense' => 'Autres dépenses',
        'loan' => 'Mensualités de crédit', 'purchase' => 'Apports d’achat', 'refund' => 'Remboursements clients',
    ];

    public function period(array $input): array
    {
        $today = CarbonImmutable::now('Africa/Casablanca');
        $start = CarbonImmutable::createFromFormat('!Y-m', $input['month'] ?? $today->format('Y-m'), 'Africa/Casablanca');

        return ['month' => $start->format('Y-m'), 'from' => $start->toDateString(),
            'to' => min($start->endOfMonth()->toDateString(), $today->toDateString()),
            'today' => $today->toDateString(), 'month_end' => $start->endOfMonth()->toDateString(),
            'trend_from' => $start->subMonths(5)->toDateString()];
    }

    /** Payment allocations are not inferred from invoice cost allocations. */
    public function ledger(): Builder
    {
        $company = Tenant::id();
        $swaps = DB::table('rental_vehicle_swaps')->where('company_id', $company)->select('reservation_id')->distinct();
        $q = DB::query()->fromSub(app(CashLedger::class)->query(), 'f')
            ->leftJoinSub(app(ExpenseReadModel::class)->query()->where('source', 'manual'), 'e', function ($j) {
                $j->on('e.id', '=', 'f.source_id')->where('f.source', 'expense');
            })->leftJoinSub($swaps, 's', function ($j) {
                $j->on('s.reservation_id', '=', 'f.source_id')->where('f.source', 'rental');
            });
        $single = "jsonb_array_length(e.allocations)=1 AND (e.allocations->0->>'vehicle_id') IS NOT NULL";
        $q->selectRaw("f.*,e.allocations AS expense_allocations,
            CASE WHEN s.reservation_id IS NOT NULL THEN NULL
                 WHEN f.source='expense' THEN CASE WHEN $single THEN (e.allocations->0->>'vehicle_id')::bigint ELSE NULL END
                 ELSE f.vehicle_id END AS report_vehicle_id,
            CASE WHEN s.reservation_id IS NOT NULL THEN 'swap'
                 WHEN f.source='expense' AND jsonb_array_length(e.allocations)>1 THEN 'shared'
                 WHEN f.source='expense' AND NOT ($single) THEN 'agency'
                 WHEN f.source<>'expense' AND f.vehicle_id IS NULL THEN 'agency' ELSE 'vehicle' END AS attribution");

        return DB::query()->fromSub($q, 'report_flows');
    }

    private function vehicle(Builder $q, ?int $vehicle): Builder
    {
        return $q->when($vehicle, fn ($q) => $q->where('report_vehicle_id', $vehicle));
    }

    private function activity(Builder $q): Builder
    {
        return $q->where('verified', true)->whereIn('channel', ['cash', 'bank', 'card'])
            ->whereNotIn('category', ['deposit', 'transfer'])->where('amount_centimes', '<>', 0);
    }

    private function totals(Builder $q): array
    {
        $r = $q->selectRaw('COALESCE(SUM(amount_centimes) FILTER(WHERE amount_centimes>0),0) AS incoming_centimes,
            COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0),0) AS outgoing_centimes,
            COALESCE(SUM(amount_centimes),0) AS net_centimes,COUNT(*) AS count')->first();

        return array_map('intval', (array) $r);
    }

    private function relatedShared(Builder $q, ?int $vehicle): Builder
    {
        $q->whereIn('attribution', ['shared', 'swap']);
        if ($vehicle) {
            $q->where(function ($q) use ($vehicle) {
                $q->whereRaw("EXISTS(SELECT 1 FROM jsonb_array_elements(expense_allocations) a WHERE (a->>'vehicle_id')::bigint=?)", [$vehicle])
                    ->orWhereExists(function ($s) use ($vehicle) {
                        $s->selectRaw('1')->from('rental_vehicle_swaps as sw')->where('sw.company_id', Tenant::id())
                            ->whereColumn('sw.reservation_id', 'report_flows.source_id')->where('report_flows.source', 'rental')
                            ->where(fn ($s) => $s->where('sw.from_vehicle_id', $vehicle)->orWhere('sw.to_vehicle_id', $vehicle));
                    });
            });
        }

        return $q;
    }

    /** An allocation settles damage using an existing deposit; it never creates cash. */
    private function damageAllocations(array $period, ?int $vehicle): Builder
    {
        $q = DB::table('rental_settlement_entries as a')
            ->join('reservations as r', fn ($j) => $j->on('r.id', '=', 'a.reservation_id')->on('r.company_id', '=', 'a.company_id'))
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'r.customer_id')->on('c.company_id', '=', 'r.company_id'))
            ->join('vehicles as v', fn ($j) => $j->on('v.id', '=', 'r.vehicle_id')->on('v.company_id', '=', 'r.company_id'))
            ->where('a.company_id', Tenant::id())->where('a.kind', 'deposit_allocate')->where('a.scope', 'damage')
            ->whereBetween('a.occurred_on', [$period['from'], $period['to']]);
        // Keep the same conservative attribution as cash flows after a vehicle swap.
        $swap = 'EXISTS(SELECT 1 FROM rental_vehicle_swaps sw WHERE sw.company_id=a.company_id AND sw.reservation_id=r.id)';
        $q->selectRaw("'deposit-allocation-' || a.id AS key, 'rental' AS source, r.id AS source_id,
            r.vehicle_id, CASE WHEN $swap THEN NULL ELSE r.vehicle_id END AS report_vehicle_id,
            concat(v.make, ' ', v.model, ' · ', v.plate) AS vehicle,
            concat(c.first_name, ' ', c.last_name) AS party,
            'Caution affectée aux dommages' AS label, 'deposit_damage' AS category,
            a.occurred_on, a.created_at, a.amount_centimes, a.reference,
            'Sans nouveau mouvement d’argent' AS channel,
            CASE WHEN $swap THEN 'swap' ELSE 'vehicle' END AS attribution");

        return $this->vehicle(DB::query()->fromSub($q, 'allocations'), $vehicle);
    }

    public function index(array $input): array
    {
        $period = $this->period($input);
        $vehicle = empty($input['vehicle_id']) ? null : (int) $input['vehicle_id'];
        $base = $this->ledger()->whereBetween('occurred_on', [$period['from'], $period['to']]);
        $q = $this->activity($this->vehicle(clone $base, $vehicle));
        $summary = $this->totals(clone $q);
        $daily = (clone $q)->selectRaw('occurred_on AS date,
            COALESCE(SUM(amount_centimes) FILTER(WHERE amount_centimes>0),0) AS incoming_centimes,
            COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0),0) AS outgoing_centimes')
            ->groupBy('occurred_on')->get()->keyBy('date');
        $days = [];
        for ($d = CarbonImmutable::parse($period['from']); $d->toDateString() <= $period['to']; $d = $d->addDay()) {
            $r = $daily->get($d->toDateString());
            $days[] = ['date' => $d->toDateString(), 'incoming_centimes' => (int) ($r?->incoming_centimes ?? 0), 'outgoing_centimes' => (int) ($r?->outgoing_centimes ?? 0)];
        }
        $history = $this->activity($this->vehicle($this->ledger(), $vehicle))->whereBetween('occurred_on', [$period['trend_from'], $period['to']])
            ->selectRaw("to_char(occurred_on,'YYYY-MM') AS month,
                COALESCE(SUM(amount_centimes) FILTER(WHERE amount_centimes>0),0) AS incoming_centimes,
                COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0),0) AS outgoing_centimes")
            ->groupByRaw("to_char(occurred_on,'YYYY-MM')")->get()->keyBy('month');
        $trend = [];
        for ($i = 0; $i < 6; $i++) {
            $m = CarbonImmutable::parse($period['trend_from'])->addMonths($i)->format('Y-m');
            $r = $history->get($m);
            $trend[] = ['month' => $m, 'incoming_centimes' => (int) ($r?->incoming_centimes ?? 0), 'outgoing_centimes' => (int) ($r?->outgoing_centimes ?? 0)];
        }
        $categories = (clone $q)->selectRaw('category,
            COALESCE(SUM(amount_centimes) FILTER(WHERE amount_centimes>0),0) AS incoming_centimes,
            COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0),0) AS outgoing_centimes,COUNT(*) AS count')->groupBy('category')->get()->map(fn ($r) => [
            'category' => $r->category, 'label' => self::CATEGORIES[$r->category], 'incoming_centimes' => (int) $r->incoming_centimes, 'outgoing_centimes' => (int) $r->outgoing_centimes, 'count' => (int) $r->count,
        ])->all();
        $vehicles = (clone $q)->whereNotNull('report_vehicle_id')->selectRaw('report_vehicle_id AS id,
            COALESCE(SUM(amount_centimes) FILTER(WHERE amount_centimes>0),0) AS incoming_centimes,
            COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0),0) AS outgoing_centimes,
            COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0 AND category IN (\'purchase\',\'loan\')),0) AS financing_centimes,
            SUM(amount_centimes) AS net_centimes,COUNT(*) AS count')->groupBy('report_vehicle_id')->get()->keyBy('id');
        $cars = DB::table('vehicles')->where('company_id', Tenant::id())->when($vehicle, fn ($q) => $q->where('id', $vehicle))->orderBy('make')->orderBy('model')->get(['id', 'make', 'model', 'plate']);
        $byVehicle = $cars->map(fn ($car) => [...(array) $car, ...array_map('intval', (array) ($vehicles->get($car->id) ?? ['incoming_centimes' => 0, 'outgoing_centimes' => 0, 'financing_centimes' => 0, 'net_centimes' => 0, 'count' => 0]))])->sortByDesc('incoming_centimes')->values()->all();
        $unassigned = [];
        foreach (['agency', 'shared', 'swap'] as $bucket) {
            $unassigned[$bucket] = $this->totals((clone $q)->where('attribution', $bucket));
        }
        $excluded = [];
        $scoped = $this->vehicle(clone $base, $vehicle);
        foreach (['deposit', 'transfer'] as $category) {
            $excluded[$category] = $this->totals((clone $scoped)->where('category', $category)->where('verified', true)->whereIn('channel', ['cash', 'bank', 'card']));
        }
        $excluded['pending'] = $this->totals((clone $scoped)->where('verified', false));
        $excluded['blocked'] = $this->totals((clone $scoped)->where('channel', 'blocked'));
        $excluded['cheque'] = $this->totals((clone $scoped)->where('channel', 'cheque')->where('verified', true));
        $shared = $this->totals($this->activity($this->relatedShared(clone $base, $vehicle)));

        return ['period' => $period, 'vehicle' => $vehicle ? DB::table('vehicles')->where('company_id', Tenant::id())->where('id', $vehicle)->first(['id', 'make', 'model', 'plate']) : null,
            'summary' => $summary, 'daily' => $days, 'trend' => $trend, 'categories' => $categories, 'vehicles' => $byVehicle,
            'damage_allocations' => ['amount_centimes' => (int) $this->damageAllocations($period, $vehicle)->sum('amount_centimes'), 'count' => $this->damageAllocations($period, $vehicle)->count()],
            'unassigned' => $unassigned, 'excluded' => $excluded, 'shared_context' => $shared,
            'balances' => app(ReportBalances::class)->index($vehicle, $period['from'], $period['month_end'])];
    }

    public function movements(array $input): array
    {
        $period = $this->period($input);
        $vehicle = empty($input['vehicle_id']) ? null : (int) $input['vehicle_id'];
        $q = $this->ledger()->whereBetween('occurred_on', [$period['from'], $period['to']]);
        $group = $input['group'] ?? 'activity';
        if ($group === 'deposit_damage') {
            $q = $this->damageAllocations($period, $vehicle);
        } elseif ($group === 'shared') {
            $q = $this->activity($this->relatedShared($q, $vehicle));
        } else {
            $q = $this->vehicle($q, $vehicle);
            if ($group === 'activity') {
                $q = $this->activity($q);
            } elseif ($group === 'pending') {
                $q->where('verified', false);
            } elseif ($group === 'cheque') {
                $q->where('verified', true)->where('channel', 'cheque');
            } else {
                $q->where('category', $group)->where('verified', true)->whereIn('channel', ['cash', 'bank', 'card']);
            }
        }
        if (($input['direction'] ?? 'all') === 'in') {
            $q->where('amount_centimes', '>', 0);
        } elseif (($input['direction'] ?? 'all') === 'out') {
            $q->where('amount_centimes', '<', 0);
        }
        if (! empty($input['category'])) {
            $q->where('category', $input['category']);
        }
        $total = (clone $q)->count();
        $last = max(1, (int) ceil($total / 20));
        $page = min($input['page'] ?? 1, $last);
        $totals = $group === 'deposit_damage'
            ? ['incoming_centimes' => 0, 'outgoing_centimes' => 0, 'net_centimes' => 0, 'count' => $total, 'allocated_centimes' => (int) (clone $q)->sum('amount_centimes')]
            : $this->totals(clone $q);
        $rows = $q->orderByDesc('occurred_on')->orderByDesc('created_at')->orderBy('key')->offset(($page - 1) * 20)->limit(20)->get()->map(function ($r) {
            $r = (array) $r;
            foreach (['source_id', 'vehicle_id', 'report_vehicle_id', 'amount_centimes'] as $key) {
                $r[$key] = $r[$key] === null ? null : (int) $r[$key];
            }
            unset($r['expense_allocations'],$r['revision'],$r['actor_membership_id']);

            return $r;
        })->all();

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'last_page' => $last, 'summary' => $totals];
    }
}
