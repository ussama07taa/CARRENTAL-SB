<?php

namespace App\Support\Vehicles;

use App\Models\Vehicle;

class MaintenanceOverview
{
    public function snapshot(string $search = ''): array
    {
        $maintenance = app(VehicleMaintenance::class);
        $vehicles = Vehicle::orderBy('id')->get();
        $q = mb_strtolower(trim($search));
        $entries = $maintenance->query()->whereIn('vehicle_id', $vehicles->modelKeys())->orderByDesc('performed_on')->orderByDesc('id')->get();
        $ids = $entries->pluck('id');
        $snapshot = ['cancellations' => app(MaintenanceCancellations::class)->rows($ids->all()), 'entries' => $entries->groupBy('vehicle_id'), 'cash' => app(MaintenancePayments::class)->cash($ids->all()), 'returns' => $maintenance->query('maintenance_returns')->whereIn('maintenance_id', $ids)->get()->keyBy('maintenance_id'), 'works' => $maintenance->query('maintenance_work_items')->whereIn('maintenance_id', $ids)->selectRaw('DISTINCT ON (item_key) *')->orderBy('item_key')->orderByDesc('version')->get()->sortBy('id')->groupBy('maintenance_id'), 'events' => $maintenance->query('maintenance_followups')->whereIn('maintenance_id', $ids)->orderBy('id')->get()->groupBy('maintenance_id')];
        $today = now('Africa/Casablanca')->toDateString();
        $rows = [];
        $files = [];
        $balance = 0;
        $provisional = 0;
        $coverage = ['vehicle_count' => $vehicles->count(), 'without_history' => [], 'without_reminder' => []];
        foreach ($vehicles as $v) {
            $data = $maintenance->listing($v, 1, $snapshot, false);
            $balance += $data['summary']['balance_centimes'];
            $provisional += $data['summary']['provisional_files'];
            $base = ['vehicle' => ['id' => $v->id, 'plate' => $v->plate, 'make' => $v->make, 'model' => $v->model], 'odometer' => $data['odometer']];
            $active = collect($data['data'])->contains(fn ($entry) => ! $entry->cancellation);
            if (! $active) {
                $coverage['without_history'][] = $base['vehicle'];
            } elseif (empty($data['reminders'])) {
                $coverage['without_reminder'][] = $base['vehicle'];
            }
            foreach ($data['data'] as $entry) {
                $items = collect($entry->items)->filter(fn ($item) => $item->status !== 'cancelled');
                $workState = $entry->cancellation || $items->isEmpty() ? 'cancelled' : ($items->contains(fn ($item) => in_array($item->status, ['pending', 'progress'], true)) ? 'pending' : ($items->contains('status', 'deferred') ? 'deferred' : 'done'));
                $garageOpen = collect($data['summary']['garage'])->contains('id', $entry->id);
                $files[] = [...$base, 'key' => 'file-'.$entry->id, 'category' => 'file', 'entry_id' => $entry->id, 'title' => $entry->title, 'work_titles' => collect($entry->items)->pluck('title')->values()->all(), 'date' => $entry->performed_on, 'km' => $entry->kilometers, 'garage' => $entry->garage, 'garage_open' => $garageOpen, 'work_state' => $workState, 'cancelled' => (bool) $entry->cancellation, 'cost_centimes' => $entry->cost_centimes, 'paid_centimes' => $entry->paid_centimes, 'balance_centimes' => $entry->balance_centimes, 'credit_centimes' => $entry->credit_centimes, 'cost_final' => $entry->cost_final, 'action' => 'open', 'state' => $garageOpen ? 'garage' : $workState, 'attention' => false];
            }
            foreach ($data['cancelled'] as $cancelled) {
                $rows[] = [...$base, 'key' => $v->id.'-cancelled-'.$cancelled->id, 'category' => 'cancelled', 'entry_id' => $cancelled->id, 'title' => $cancelled->title, 'reason' => $cancelled->cancellation->reason, 'date' => substr($cancelled->cancellation->created_at, 0, 10), 'state' => 'cancelled', 'attention' => false, 'action' => 'detail'];
            }
            foreach ($data['reminders'] as $r) {
                $rows[] = [...$base, 'key' => $v->id.'-reminder-'.$r['id'].'-'.($r['item_key'] ?? $r['kind']), 'category' => 'reminder', 'entry_id' => null, 'source_entry_id' => $r['id'], 'title' => $r['title'], 'kind' => $r['kind'], 'date' => $r['next_on'], 'km' => $r['next_km'], 'remaining_km' => $r['km'], 'needs_odometer' => $r['needs_odometer'], 'state' => $r['state'], 'attention' => $r['state'] !== 'scheduled' || $r['needs_odometer'], 'action' => 'create'];
            }
            foreach ($data['followups'] as $f) {
                if ($f['visit']) {
                    continue;
                }$rows[] = [...$base, 'key' => $v->id.'-deferred-'.$f['item_key'], 'category' => 'deferred', 'entry_id' => $f['entry_id'], 'item_key' => $f['item_key'], 'title' => $f['title'], 'reason' => $f['reason'], 'date' => $f['follow_up_on'], 'state' => $f['follow_up_on'] && $f['follow_up_on'] < $today ? 'overdue' : ($f['follow_up_on'] === $today ? 'due' : 'deferred'), 'attention' => ! $f['follow_up_on'] || $f['follow_up_on'] <= $today, 'action' => 'followup'];
            }
            foreach ($data['summary']['garage'] as $g) {
                $rows[] = [...$base, 'key' => $v->id.'-garage-'.$g['id'].'-'.($g['item_key'] ?? 'initial'), 'category' => 'garage', 'entry_id' => $g['id'], 'item_key' => $g['item_key'] ?? null, 'title' => $g['title'], 'date' => $g['since'], 'state' => 'garage', 'attention' => true, 'action' => isset($g['item_key']) ? 'followup' : 'return'];
            }
            foreach ($data['credits'] as $credit) {
                $rows[] = [...$base, 'key' => $v->id.'-credit-'.$credit['id'], 'category' => 'credits', 'entry_id' => $credit['id'], 'title' => $credit['title'], 'date' => null, 'state' => 'credit', 'attention' => $credit['cost_final'], 'amount_centimes' => $credit['amount_centimes'], 'cost_final' => $credit['cost_final'], 'action' => 'open'];
            }
            foreach ($data['debts'] as $d) {
                $rows[] = [...$base, 'key' => $v->id.'-payment-'.$d['id'], 'category' => 'payments', 'entry_id' => $d['id'], 'title' => $d['title'], 'date' => $d['due_on'], 'state' => $d['state'], 'attention' => $d['due_on'] && $d['due_on'] <= $today, 'amount_centimes' => $d['amount_centimes'], 'cost_final' => $d['cost_final'], 'action' => 'pay'];
            }
        }

        $vehicleCount = $vehicles->count();
        if ($q !== '') {
            $matchesVehicle = fn ($v) => str_contains(mb_strtolower($v['plate'].' '.$v['make'].' '.$v['model']), $q);
            $matchesFile = fn ($r) => $matchesVehicle($r['vehicle']) || str_contains(mb_strtolower($r['title'].' '.implode(' ', $r['work_titles']).' Dossier #'.$r['entry_id'].' Dossier '.$r['entry_id']), $q);
            $files = array_values(array_filter($files, $matchesFile));
            $fileIds = array_column($files, 'entry_id');
            $rows = array_values(array_filter($rows, fn ($r) => $matchesVehicle($r['vehicle']) || str_contains(mb_strtolower($r['title']), $q) || in_array($r['source_entry_id'] ?? $r['entry_id'], $fileIds, true)));
            $matchedVehicles = $vehicles->filter(fn ($v) => $matchesVehicle($v->toArray()))->modelKeys();
            $vehicleCount = count(array_unique([...$matchedVehicles, ...array_column(array_column($files, 'vehicle'), 'id'), ...array_column(array_column($rows, 'vehicle'), 'id')]));
            $activeFiles = array_filter($files, fn ($r) => ! $r['cancelled']);
            $balance = array_sum(array_column($activeFiles, 'balance_centimes'));
            $provisional = count(array_filter($activeFiles, fn ($r) => ! $r['cost_final']));
        }

        return ['files' => $files, 'rows' => $rows, 'balance_centimes' => $balance, 'provisional_files' => $provisional, 'vehicle_count' => $vehicleCount, 'coverage' => $coverage, 'today' => $today];
    }
}
