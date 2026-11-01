<?php

namespace App\Support\Finance;

use App\Models\Company;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CashRegister
{
    public function ready(): bool
    {
        return Schema::hasTable('cash_counts') && Schema::hasTable('cash_entries');
    }

    public function currentEntries(): Builder
    {
        return DB::query()->fromSub(DB::table('cash_entries')->where('company_id', Tenant::id())->selectRaw('DISTINCT ON(entry_key) *')->orderBy('entry_key')->orderByDesc('version'), 'current_cash');
    }

    public function opening(): ?object
    {
        return $this->ready() ? $this->currentEntries()->where('kind', 'opening')->first() : null;
    }

    public function snapshot(string $date): array
    {
        $opening = $this->opening();
        if (! $opening) {
            return ['opening' => null, 'expected_centimes' => null, 'ledger_hash' => null, 'revision' => null, 'previous_count' => null];
        }
        $openingData = ['id' => $opening->id, 'date' => $opening->occurred_on, 'amount_centimes' => (int) $opening->amount_centimes, 'version' => $opening->version, 'reason' => $opening->reason];
        if ($date < $opening->occurred_on) {
            return ['opening' => $openingData, 'expected_centimes' => null, 'ledger_hash' => null, 'revision' => null, 'previous_count' => null];
        }
        $q = app(CashLedger::class)->query()->where('verified', true)->where('channel', 'cash')->whereBetween('occurred_on', [$opening->occurred_on, $date]);
        $totals = $q->selectRaw("COALESCE(SUM(amount_centimes),0) AS net,md5(COALESCE(string_agg(concat_ws(':',key,occurred_on,amount_centimes,revision),'|' ORDER BY key),'')) AS digest")->first();
        $hash = hash('sha256', implode(':', [$opening->id, $opening->amount_centimes, $opening->occurred_on, $date, $totals->digest]));
        $last = DB::table('cash_counts')->where('company_id', Tenant::id())->where('counted_on', $date)->latest('id')->first();
        $lastData = $last ? ['id' => $last->id, 'expected_centimes' => (int) $last->expected_centimes, 'counted_centimes' => (int) $last->counted_centimes, 'difference_centimes' => (int) $last->difference_centimes, 'note' => $last->note, 'created_at' => $last->created_at, 'stale' => ! hash_equals($last->ledger_hash, $hash)] : null;

        return ['opening' => ['id' => $opening->id, 'date' => $opening->occurred_on, 'amount_centimes' => (int) $opening->amount_centimes, 'version' => $opening->version, 'reason' => $opening->reason], 'expected_centimes' => (int) $opening->amount_centimes + (int) $totals->net, 'ledger_hash' => $hash, 'revision' => hash('sha256', $hash.':'.($last?->id ?? 0)), 'previous_count' => $lastData];
    }

    public function index(array $input): array
    {
        $today = now('Africa/Casablanca')->toDateString();
        $from = $input['from'] ?? $today;
        $to = $input['to'] ?? $today;
        $snapshot = $this->snapshot($to);
        $effectiveFrom = $snapshot['expected_centimes'] === null ? $from : max($from, $snapshot['opening']['date'] ?? $from);
        $query = app(CashLedger::class)->query()->whereBetween('occurred_on', [$from, $to]);
        if ($effectiveFrom > $from) {
            $query->where(fn ($q) => $q->where('channel', '<>', 'cash')->orWhere('occurred_on', '>=', $effectiveFrom));
        }
        $channels = (clone $query)->where('verified', true)->where('channel', '<>', 'blocked')->selectRaw('channel,COALESCE(SUM(amount_centimes) FILTER(WHERE amount_centimes>0),0) AS incoming,COALESCE(-SUM(amount_centimes) FILTER(WHERE amount_centimes<0),0) AS outgoing,COALESCE(SUM(amount_centimes),0) AS net,COUNT(*) AS count')->groupBy('channel')->get()->mapWithKeys(fn ($r) => [$r->channel => ['incoming' => (int) $r->incoming, 'outgoing' => (int) $r->outgoing, 'net' => (int) $r->net, 'count' => (int) $r->count]])->all();
        $pending = (clone $query)->where('verified', false)->sum('amount_centimes');
        $blocked = (clone $query)->where('channel', 'blocked')->count();
        $query->where('verified', true)->where('channel', '<>', 'blocked')->where('amount_centimes', '<>', 0);
        $channel = $input['channel'] ?? 'cash';
        if ($channel === 'noncash') {
            $query->whereIn('channel', ['bank', 'card', 'cheque']);
        } else {
            $query->where('channel', 'cash');
        }
        if (($input['direction'] ?? 'all') === 'in') {
            $query->where('amount_centimes', '>', 0);
        } elseif (($input['direction'] ?? 'all') === 'out') {
            $query->where('amount_centimes', '<', 0);
        }
        if ($q = trim($input['q'] ?? '')) {
            $query->whereRaw("strpos(lower(concat_ws(' ',label,party,vehicle,reference,actor)),lower(?))>0", [$q]);
        }
        $total = (clone $query)->count();
        $last = max(1, (int) ceil($total / 20));
        $page = min((int) ($input['page'] ?? 1), $last);
        $rows = $query->orderByDesc('occurred_on')->orderByDesc('created_at')->orderBy('key')->offset(($page - 1) * 20)->limit(20)->get()->map(function ($r) {
            $a = (array) $r;
            foreach (['source_id', 'vehicle_id', 'actor_membership_id', 'amount_centimes'] as $k) {
                $a[$k] = $a[$k] === null ? null : (int) $a[$k];
            }

            return $a;
        })->all();
        $start = null;
        if ($snapshot['expected_centimes'] !== null) {
            $before = app(CashLedger::class)->query()->where('verified', true)->where('channel', 'cash')->where('occurred_on', '>=', $snapshot['opening']['date'])->where('occurred_on', '<', $from)->sum('amount_centimes');
            $start = $snapshot['opening']['amount_centimes'] + (int) $before;
        }
        $history = $this->ready() ? DB::table('cash_counts as c')->join('company_memberships as m', function ($j) {
            $j->on('m.id', '=', 'c.actor_membership_id')->on('m.company_id', '=', 'c.company_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('c.company_id', Tenant::id())->orderByDesc('c.id')->limit(10)->get(['c.id', 'c.counted_on', 'c.expected_centimes', 'c.counted_centimes', 'c.difference_centimes', 'c.note', 'c.created_at', 'u.name as actor'])->map(fn ($r) => ['id' => $r->id, 'date' => $r->counted_on, 'expected_centimes' => (int) $r->expected_centimes, 'counted_centimes' => (int) $r->counted_centimes, 'difference_centimes' => (int) $r->difference_centimes, 'note' => $r->note, 'created_at' => $r->created_at, 'actor' => $r->actor])->all() : [];

        $manualHistory = $this->ready() ? $this->currentEntries()->join('company_memberships as m', function ($j) {
            $j->on('m.company_id', '=', 'current_cash.company_id')->on('m.id', '=', 'current_cash.actor_membership_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('kind', '<>', 'opening')->orderByDesc('current_cash.id')->limit(10)->get(['current_cash.id', 'version', 'kind', 'occurred_on', 'amount_centimes', 'reason', 'current_cash.created_at', 'u.name as actor'])->map(fn ($r) => [...(array) $r, 'amount_centimes' => (int) $r->amount_centimes])->all() : [];

        return ['manual_history' => $manualHistory, 'ready' => $this->ready(), 'today' => $today, 'from' => $from, 'to' => $to, 'channels' => $channels, 'pending_centimes' => (int) $pending, 'blocked_count' => $blocked, 'cash' => [...$snapshot, 'period_opening_centimes' => $start, 'effective_from' => $effectiveFrom], 'data' => $rows, 'total' => $total, 'page' => $page, 'last_page' => $last, 'history' => $history];
    }

    private function lock(): void
    {
        Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
    }

    public function saveEntry(int $actor, array $input, ?int $id = null): int
    {
        abort_unless($this->ready(), 503, 'Le suivi de caisse attend son activation.');
        $hash = hash('sha256', json_encode([$id, $input]));

        return DB::transaction(function () use ($actor, $input, $id, $hash) {
            $this->lock();
            $old = DB::table('cash_entries')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette demande correspond à une autre saisie.');

                return (int) $old->id;
            }
            $entry = $id ? $this->currentEntries()->where('id', $id)->first() : null;
            if ($id) {
                abort_unless($entry, 409, 'Cette opération a changé. Actualisez la caisse.');
                abort_unless($entry->version === $input['expected_version'], 409, 'Cette opération a changé.');
                abort_unless($entry->kind === $input['kind'] && $entry->occurred_on === $input['occurred_on'], 422, 'Conservez le type et la date de l’opération. Corrigez seulement son montant et son motif.');
            }
            $opening = $this->opening();
            if ($input['kind'] === 'opening') {
                abort_if(! $id && $opening, 409, 'Le solde de départ est déjà enregistré.');
            } else {
                abort_unless($opening, 422, 'Définissez d’abord le solde de départ.');
                abort_if($input['occurred_on'] < $opening->occurred_on, 422, 'La date précède le début du suivi de caisse.');
                abort_if(! $id && $input['amount_centimes'] === 0, 422, 'Indiquez un montant positif.');
            }

            return DB::table('cash_entries')->insertGetId(['company_id' => Tenant::id(), 'actor_membership_id' => $actor, 'entry_key' => $entry?->entry_key ?? (string) Str::uuid(), 'version' => ($entry?->version ?? 0) + 1, 'kind' => $input['kind'], 'occurred_on' => $input['occurred_on'], 'amount_centimes' => $input['amount_centimes'], 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        });
    }

    public function count(int $actor, array $input): int
    {
        abort_unless($this->ready(), 503, 'Le suivi de caisse attend son activation.');
        $hash = hash('sha256', json_encode($input));

        return DB::transaction(function () use ($actor, $input, $hash) {
            $this->lock();
            $old = DB::table('cash_counts')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette demande correspond à un autre comptage.');

                return (int) $old->id;
            }
            $s = $this->snapshot($input['counted_on']);
            abort_unless($s['expected_centimes'] !== null, 422, 'Le comptage doit suivre le début du suivi de caisse.');
            abort_unless(hash_equals($s['revision'], $input['expected_revision']), 409, 'Les mouvements ou le dernier comptage ont changé. Actualisez et vérifiez les montants.');
            $difference = $input['counted_centimes'] - $s['expected_centimes'];
            abort_if($difference !== 0 && mb_strlen(trim($input['note'] ?? '')) < 3, 422, 'Expliquez le montant manquant ou en trop. Aucun ajustement automatique ne sera créé.');

            return DB::table('cash_counts')->insertGetId(['company_id' => Tenant::id(), 'actor_membership_id' => $actor, 'counted_on' => $input['counted_on'], 'expected_centimes' => $s['expected_centimes'], 'counted_centimes' => $input['counted_centimes'], 'difference_centimes' => $difference, 'ledger_hash' => $s['ledger_hash'], 'snapshot' => json_encode($s), 'note' => $input['note'] ?? '', 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        });
    }

    public function entry(int $id): array
    {
        $entry = DB::table('cash_entries')->where('company_id', Tenant::id())->where('id', $id)->first();
        abort_unless($entry, 404);
        $rows = DB::table('cash_entries as e')->join('company_memberships as m', function ($j) {
            $j->on('m.company_id', '=', 'e.company_id')->on('m.id', '=', 'e.actor_membership_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('e.company_id', Tenant::id())->where('entry_key', $entry->entry_key)->orderByDesc('version')->get(['e.*', 'u.name as actor'])->map(fn ($r) => ['id' => $r->id, 'version' => $r->version, 'kind' => $r->kind, 'occurred_on' => $r->occurred_on, 'amount_centimes' => (int) $r->amount_centimes, 'reason' => $r->reason, 'created_at' => $r->created_at, 'actor' => $r->actor])->all();

        return ['data' => $rows[0], 'history' => $rows];
    }
}
