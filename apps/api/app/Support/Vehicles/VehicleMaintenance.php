<?php

namespace App\Support\Vehicles;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleMaintenance
{
    public function query(string $table = 'vehicle_maintenance'): Builder
    {
        return DB::table($table)->where('company_id', Tenant::id());
    }

    public function actor(int $user): int
    {
        $m = CompanyMembership::where('user_id', $user)->firstOrFail();
        abort_unless($m->is_primary_responsible, 403, 'Le suivi financier des interventions est réservé au responsable.');

        return $m->id;
    }

    public function lock(int $vehicle): Vehicle
    {
        Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();

        return Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
    }

    public function entry(int $vehicle, int $id): object
    {
        return (new MaintenanceWork)->financial($this->query()->where('vehicle_id', $vehicle)->where('id', $id)->firstOrFail());
    }

    public function audit(int $vehicle, int $actor, string $action, array $data): void
    {
        DB::table('vehicle_events')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor, 'action' => $action, 'before' => null, 'after' => json_encode($data), 'created_at' => now()]);
    }

    public function hash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data));
    }

    public function replay(string $table, string $key, string $hash): ?object
    {
        $old = $this->query($table)->where('request_key', $key)->first();
        abort_if($old && ! hash_equals($old->request_hash, $hash), 409, 'Cette demande a déjà été utilisée avec des données différentes.');

        return $old;
    }

    public function paid(int $id): int
    {
        return (int) ((new MaintenancePayments)->totals([$id])[$id] ?? 0);
    }

    public function assertActive(object $entry): void
    {
        abort_if($entry->cancellation !== null, 409, 'Ce dossier est annulé. Son historique reste consultable, mais il ne peut plus être modifié.');
    }

    public function pay(object $entry, int $actor, array $input, string $hash): int
    {
        $this->assertActive($entry);
        if (($entry->cost_final ?? true) && (int) $input['amount_centimes'] > (int) $entry->cost_centimes - $this->paid($entry->id)) {
            throw ValidationException::withMessages(['amount_centimes' => 'Le paiement dépasse le montant restant. Actualisez le fichier.']);
        }
        $id = DB::table('maintenance_payments')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry->id, 'actor_membership_id' => $actor, 'amount_centimes' => (int) $input['amount_centimes'], 'paid_on' => $input['paid_on'], 'method' => $input['method'], 'reference' => $input['reference'] ?? null, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        $this->audit($entry->vehicle_id, $actor, 'maintenance_payment', ['maintenance_id' => $entry->id, 'payment_id' => $id, 'amount_centimes' => (int) $input['amount_centimes']]);

        return $id;
    }

    public function deadline(?string $date, ?int $target, ?int $current): array
    {
        $days = $date === null ? null : (int) CarbonImmutable::today('Africa/Casablanca')->diffInDays(CarbonImmutable::parse($date, 'Africa/Casablanca'), false);
        $km = $target === null || $current === null ? null : $target - $current;
        $state = ($days !== null && $days < 0) || ($km !== null && $km < 0) ? 'overdue' : (($days === 0 || $km === 0) ? 'due' : (($days !== null && $days <= 7) ? 'urgent' : ((($days !== null && $days <= 30) || ($km !== null && $km <= 1000)) ? 'soon' : 'scheduled')));

        return ['state' => $state, 'days' => $days, 'km' => $km, 'needs_odometer' => $target !== null && $current === null];
    }

    public function recordReading(Vehicle $v, ?int $km, ?string $date, int $actor): void
    {
        if ($km === null || $date === null || ($v->current_odometer_date && $date < $v->current_odometer_date)) {
            return;
        }
        if ($v->current_odometer !== null && $km < $v->current_odometer) {
            throw ValidationException::withMessages(['kilometers' => 'Le relevé est inférieur au dernier compteur. Vérifiez la date réelle de l’intervention.']);
        }
        DB::table('vehicle_odometer_readings')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $v->id, 'actor_membership_id' => $actor, 'kilometers' => $km, 'recorded_on' => $date, 'kind' => 'reading', 'reason' => 'Dossier entretien', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', $v->id.'|'.$km.'|'.$date), 'created_at' => now()]);
        $v->current_odometer = $km;
        $v->current_odometer_date = $date;
        $v->version++;
        $v->save();
    }

    public function listing(Vehicle $vehicle, int $page, ?array $snapshot = null, bool $paginate = true): array
    {
        $all = $snapshot !== null ? ($snapshot['entries'][$vehicle->id] ?? collect())->map(fn ($row) => clone $row) : $this->query()->where('vehicle_id', $vehicle->id)->orderByDesc('performed_on')->orderByDesc('id')->get();
        $ids = $all->pluck('id');
        $cash = $snapshot !== null ? $snapshot['cash'] : (new MaintenancePayments)->cash($ids->all());
        $paid = $cash->pluck('net_centimes', 'maintenance_id');
        $returns = $snapshot !== null ? $snapshot['returns'] : $this->query('maintenance_returns')->whereIn('maintenance_id', $ids)->get()->keyBy('maintenance_id');
        if ($snapshot === null) {
            $snapshot = ['cash' => $cash, 'returns' => $returns, 'works' => $this->query('maintenance_work_items')->whereIn('maintenance_id', $ids)->selectRaw('DISTINCT ON (item_key) *')->orderBy('item_key')->orderByDesc('version')->get()->sortBy('id')->groupBy('maintenance_id'), 'events' => $this->query('maintenance_followups')->whereIn('maintenance_id', $ids)->orderBy('id')->get()->groupBy('maintenance_id'), 'cancellations' => app(MaintenanceCancellations::class)->rows($ids->all())];
        }
        $all = $all->map(fn ($e) => (new MaintenanceWork)->financial($e, $snapshot));
        $active = $all->filter(fn ($e) => ! $e->cancellation && collect($e->items)->contains(fn ($i) => $i->status !== 'cancelled'));
        $replaced = $active->pluck('replaces_id')->filter()->all();
        $oil = $active->firstWhere('kind', 'oil')?->id;
        $followups = [];
        $reminders = [];
        $debts = [];
        $credits = [];
        $garage = [];
        $total = 0;
        $balance = 0;
        $rows = $all->map(function ($e) use ($snapshot, $vehicle, $cash, $paid, $returns, $replaced, $oil, &$reminders, &$debts, &$credits, &$garage, &$total, &$balance, &$followups) {
            $tracking = app(MaintenanceFollowups::class)->tracking($e, $snapshot);
            foreach ($e->items as $work) {
                if ($work->status === 'cancelled') {
                    continue;
                }
                $track = $tracking[$work->item_key ?? ''] ?? null;
                if ($work->status === 'deferred' || ($track['visit'] ?? null)) {
                    $followups[] = ['entry_id' => $e->id, 'item_key' => $work->item_key, 'title' => $work->title, 'reason' => $work->reason, 'status' => $work->status, 'revision' => $e->revision, ...($track ?? [])];
                    if ($track['visit'] ?? null) {
                        $garage[] = ['id' => $e->id, 'item_key' => $work->item_key, 'title' => $work->title.' · reprise', 'since' => $track['visit']['started_on']];
                    }
                }
            }
            $e->gross_paid_centimes = (int) ($cash[$e->id]->gross_centimes ?? 0);
            $e->refunded_centimes = (int) ($cash[$e->id]->refunded_centimes ?? 0);
            $e->paid_centimes = (int) ($paid[$e->id] ?? 0);
            $e->balance_centimes = max(0, $e->cost_centimes - $e->paid_centimes);
            $e->credit_centimes = max(0, $e->paid_centimes - $e->cost_centimes);
            $e->has_file = $e->path !== null;
            $e->returned_on = $returns[$e->id]->returned_on ?? null;
            $e->in_garage = (bool) $e->in_garage;
            $e->garage_open = $e->in_garage && ! $e->returned_on;
            if ($e->garage_open) {
                $garage[] = ['id' => $e->id, 'title' => $e->title, 'since' => $e->performed_on];
            }
            $e->reminder_active = ! $e->cancellation && $e->items[0]->status !== 'cancelled' && ($e->items[0]->legacy ?? false) && ($e->next_on !== null || $e->next_km !== null) && ! in_array($e->id, $replaced) && ($e->kind !== 'oil' || $e->id === $oil);
            if ($e->reminder_active) {
                $reminders[] = ['id' => $e->id, 'title' => $e->title, 'kind' => $e->kind, 'next_on' => $e->next_on, 'next_km' => $e->next_km, ...$this->deadline($e->next_on, $e->next_km, $vehicle->current_odometer)];
            }
            if ($e->balance_centimes > 0) {
                $debts[] = ['id' => $e->id, 'title' => $e->title, 'amount_centimes' => $e->balance_centimes, 'cost_final' => $e->cost_final, 'due_on' => $e->payment_due_on, ...$this->deadline($e->payment_due_on, null, null)];
            }
            if ($e->credit_centimes > 0) {
                $credits[] = ['id' => $e->id, 'title' => $e->title, 'amount_centimes' => $e->credit_centimes, 'cost_final' => $e->cost_final];
            }
            $total += $e->cost_centimes;
            $balance += $e->balance_centimes;
            unset($e->company_id,$e->path,$e->sha256,$e->request_hash,$e->request_key,$e->actor_membership_id);

            return $e;
        });
        $taskReminders = [];
        foreach ($rows as $row) {
            if ($row->items[0]->legacy ?? false) {
                continue;
            }foreach ($row->items as $item) {
                if ($item->status !== 'done') {
                    continue;
                }$category = in_array($item->kind, ['service', 'repair'], true) ? $item->kind.':'.mb_strtolower(trim($item->title)) : $item->kind;
                $stamp = ($item->completed_on ?? $row->performed_on).'|'.str_pad((string) $item->id, 20, '0', STR_PAD_LEFT);
                if (! isset($taskReminders[$category]) || $stamp > $taskReminders[$category]['stamp']) {
                    $taskReminders[$category] = ['stamp' => $stamp, 'item' => $item, 'row' => $row];
                }
            }
        }
        foreach ($taskReminders as $category => $value) {
            $item = $value['item'];
            $row = $value['row'];
            if ($category === 'oil') {
                $reminders = array_values(array_filter($reminders, fn ($r) => $r['kind'] !== 'oil' || ($all->firstWhere('id', $r['id'])->performed_on ?? '') > $item->completed_on));
                if (collect($reminders)->contains('kind', 'oil')) {
                    continue;
                }
            }if ($item->next_on || $item->next_km) {
                $reminders[] = ['id' => $row->id, 'item_key' => $item->item_key, 'title' => $item->title, 'kind' => $item->kind, 'next_on' => $item->next_on, 'next_km' => $item->next_km, ...$this->deadline($item->next_on, $item->next_km, $vehicle->current_odometer)];
            }
        }
        $rank = ['overdue' => 0, 'due' => 1, 'urgent' => 2, 'soon' => 3, 'scheduled' => 4];
        usort($reminders, fn ($a, $b) => $rank[$a['state']] <=> $rank[$b['state']]);

        return ['cancelled' => $rows->filter(fn ($e) => $e->cancellation !== null)->values(), 'followups' => $followups, 'data' => ($paginate ? $rows->slice(($page - 1) * 20, 20) : $rows)->values(), 'total' => $rows->count(), 'page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / 20)), 'summary' => ['cost_centimes' => $total, 'balance_centimes' => $balance, 'provisional_files' => $rows->where('cost_final', false)->count(), 'credit_centimes' => $rows->sum('credit_centimes'), 'garage' => $garage], 'reminders' => $reminders, 'debts' => $debts, 'credits' => $credits, 'odometer' => ['value' => $vehicle->current_odometer, 'date' => $vehicle->current_odometer_date], 'today' => now('Africa/Casablanca')->toDateString()];
    }
}
