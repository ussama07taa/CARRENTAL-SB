<?php

namespace App\Http\Controllers;

use App\Support\Vehicles\MaintenanceOverview;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceOverviewController extends Controller
{
    public function index(Request $request, VehicleMaintenance $maintenance): JsonResponse
    {
        $maintenance->actor($request->user()->id);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tab' => ['nullable', Rule::in(['attention', 'deferred', 'garage', 'payments', 'all', 'cancelled', 'credits', 'files'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $snapshot = app(MaintenanceOverview::class)->snapshot($input['q'] ?? '');
        $rows = $snapshot['rows'];
        $balance = $snapshot['balance_centimes'];
        $provisional = $snapshot['provisional_files'];
        $today = $snapshot['today'];
        $counts = ['files' => count($snapshot['files']), 'attention' => 0, 'deferred' => 0, 'garage' => 0, 'payments' => 0, 'cancelled' => 0, 'credits' => 0, 'all' => count(array_filter($rows, fn ($r) => $r['category'] !== 'cancelled'))];
        foreach ($rows as $r) {
            if ($r['attention']) {
                $counts['attention']++;
            }if (isset($counts[$r['category']])) {
                $counts[$r['category']]++;
            }
        }
        $tab = $input['tab'] ?? 'attention';
        $filtered = array_values(array_filter($rows, fn ($r) => ($tab === 'all' && $r['category'] !== 'cancelled') || ($tab === 'attention' ? $r['attention'] : $r['category'] === $tab)));
        $rank = ['overdue' => 0, 'due' => 1, 'garage' => 2, 'urgent' => 3, 'soon' => 4, 'deferred' => 5, 'scheduled' => 6, 'cancelled' => 7, 'credit' => 3];
        usort($filtered, fn ($a, $b) => [$rank[$a['state']], $a['date'] ?? '9999', $a['key']] <=> [$rank[$b['state']], $b['date'] ?? '9999', $b['key']]);
        if ($tab === 'files') {
            $filtered = $snapshot['files'];
            usort($filtered, fn ($a, $b) => [$b['date'], $b['entry_id']] <=> [$a['date'], $a['entry_id']]);
        }
        $total = count($filtered);
        $last = max(1, (int) ceil($total / 20));
        $page = min((int) ($input['page'] ?? 1), $last);

        return response()->json(['data' => array_slice($filtered, ($page - 1) * 20, 20), 'total' => $total, 'page' => $page, 'last_page' => $last, 'counts' => $counts, 'balance_centimes' => $balance, 'provisional_files' => $provisional, 'vehicle_count' => $snapshot['vehicle_count'], 'coverage' => $snapshot['coverage'], 'today' => $today]);
    }
}
