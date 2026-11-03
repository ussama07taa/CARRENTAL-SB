<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Rentals\DailyRentalOverview;
use App\Support\Vehicles\MaintenanceOverview;
use App\Support\Vehicles\VehicleDocumentAlerts;
use App\Support\Vehicles\VehicleLoans;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardOverviewController extends Controller
{
    public function index(Request $request, VehicleLoans $loans, MaintenanceOverview $maintenance): JsonResponse
    {
        $loans->actor($request->user()->id);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tab' => ['nullable', Rule::in(['all', 'documents', 'maintenance', 'credit', 'garage'])], 'horizon' => ['nullable', Rule::in(['0', '7', '30', 'all'])], 'focus' => ['nullable', Rule::in(['all', 'urgent', 'missing', 'expiring', 'expired'])], 'page' => ['nullable', 'integer', 'min:1'], 'task_group' => ['nullable', Rule::in(['readiness', 'settlement', 'pending'])], 'task_page' => ['nullable', 'integer', 'min:1'], 'rental_group' => ['nullable', Rule::in(['departures', 'returns', 'overdue'])], 'rental_page' => ['nullable', 'integer', 'min:1']]);

        return DB::transaction(function () use ($input, $loans, $maintenance): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $today = CarbonImmutable::today('Africa/Casablanca');
            $vehicles = Vehicle::orderBy('id')->get()->keyBy('id');
            $covers = VehiclePhoto::current()->whereIn('vehicle_id', $vehicles->keys())->where('slot', 'cover')->orderByDesc('version')->get()->unique('vehicle_id')->keyBy('vehicle_id');
            $fleetPreview = $vehicles->sortByDesc('id')->sortByDesc(fn (Vehicle $vehicle): int => $covers->get($vehicle->id)?->id ?? 0)->take(4)->values()->map(fn (Vehicle $vehicle): array => [
                ...$vehicle->only(['id', 'plate', 'make', 'model', 'year', 'fuel', 'transmission', 'current_odometer']),
                'cover_id' => $covers->get($vehicle->id)?->id,
            ]);
            $snapshot = $maintenance->snapshot();
            $rows = [];
            foreach ($snapshot['rows'] as $row) {
                if ($row['category'] !== 'cancelled') {
                    $rows[] = [...$row, 'key' => 'maintenance-'.$row['key'], 'group' => 'maintenance'];
                }
            }
            $rows = [...$rows, ...app(VehicleDocumentAlerts::class)->rows()];
            $contracts = $loans->summaries()->get()->keyBy('id');
            $installments = DB::query()->fromSub($loans->installments(), 's')->whereIn('loan_id', $contracts->keys())->where('remaining_centimes', '>', 0)->orderBy('due_on')->get();
            foreach ($installments as $item) {
                $loan = $contracts[$item->loan_id];
                $days = (int) $today->diffInDays(CarbonImmutable::parse($item->due_on, 'Africa/Casablanca'), false);
                $rows[] = ['key' => 'credit-'.$item->id, 'group' => 'credit', 'category' => 'installment', 'vehicle' => ['id' => $loan->vehicle_id, 'plate' => $loan->plate, 'make' => $loan->make, 'model' => $loan->model], 'title' => $loan->lender.' · Échéance '.$item->position, 'reason' => 'Contrat '.$loan->reference, 'date' => $item->due_on, 'state' => $days < 0 ? 'overdue' : ($days === 0 ? 'due' : ($days <= 7 ? 'urgent' : ($days <= 30 ? 'soon' : 'scheduled'))), 'attention' => $days <= 30, 'action' => 'loan', 'loan_id' => $loan->id, 'installment_id' => $item->id, 'amount_centimes' => (int) $item->remaining_centimes, 'cost_final' => true];
            }
            $within = function (array $row, string $horizon) use ($today): bool {
                if ($horizon === 'all' || in_array($row['category'], ['garage', 'credits'], true) || ($row['needs_odometer'] ?? false) || ($row['missing_information'] ?? false)) {
                    return true;
                }
                if (! $row['date']) {
                    return $row['category'] !== 'reminder' || $row['state'] !== 'scheduled';
                }

                return $row['date'] <= $today->addDays((int) $horizon)->toDateString() || ($row['category'] === 'reminder' && isset($row['remaining_km']) && $row['remaining_km'] <= 1000);
            };
            $near = collect($rows)->filter(fn ($r) => $within($r, '30'));
            $isUrgent = fn (array $row): bool => in_array($row['state'], ['overdue', 'due'], true) || ($row['group'] === 'documents' && $row['date'] === $today->toDateString());
            $documents = $near->where('group', 'documents');
            $summary = ['vehicles' => $vehicles->count(), 'garage_vehicles' => collect($rows)->where('category', 'garage')->pluck('vehicle.id')->unique()->count(), 'documents' => $documents->count(), 'documents_missing' => $documents->where('document_status', 'missing')->count(), 'documents_expiring' => $documents->where('document_status', 'expiring')->count(), 'documents_expired' => $documents->where('document_status', 'expired')->count(), 'installments' => $near->where('group', 'credit')->count(), 'urgent' => $near->filter($isUrgent)->count(), 'loan_remaining_centimes' => (int) $contracts->sum('remaining_centimes'), 'loan_due_centimes' => (int) $contracts->sum('overdue_centimes') + (int) $contracts->sum('upcoming_centimes'), 'maintenance_remaining_centimes' => $snapshot['balance_centimes'], 'provisional_files' => $snapshot['provisional_files'], 'garage_credit_centimes' => (int) collect($rows)->where('category', 'credits')->sum('amount_centimes')];
            $q = mb_strtolower(trim($input['q'] ?? ''));
            $filtered = array_values(array_filter($rows, function ($r) use ($input, $within, $q, $isUrgent) {
                $tab = $input['tab'] ?? 'all';
                $focus = $input['focus'] ?? 'all';
                $matchesFocus = $focus === 'all' || ($focus === 'urgent' ? $isUrgent($r) : ($r['document_status'] ?? null) === $focus);

                return $matchesFocus && ($tab === 'all' || $tab === $r['group'] || ($tab === 'garage' && $r['category'] === 'garage')) && $within($r, $input['horizon'] ?? '30') && ($q === '' || str_contains(mb_strtolower(implode(' ', $r['vehicle']).' '.$r['title'].' '.($r['reason'] ?? '')), $q));
            }));
            $rank = ['overdue' => 0, 'due' => 1, 'garage' => 2, 'unready' => 2, 'urgent' => 3, 'soon' => 4, 'credit' => 5, 'deferred' => 5, 'missing' => 6, 'missing_file' => 6, 'scheduled' => 7];
            usort($filtered, fn ($a, $b) => [$rank[$a['state']] ?? 6, $a['date'] ?? '9999', $a['key']] <=> [$rank[$b['state']] ?? 6, $b['date'] ?? '9999', $b['key']]);
            $last = max(1, (int) ceil(count($filtered) / 12));
            $page = min((int) ($input['page'] ?? 1), $last);

            return response()->json(['followups' => app(DailyRentalOverview::class)->followups($input['task_group'] ?? 'readiness', (int) ($input['task_page'] ?? 1)), 'rentals' => app(DailyRentalOverview::class)->read($input['rental_group'] ?? 'departures', (int) ($input['rental_page'] ?? 1)), 'summary' => $summary, 'fleet_preview' => $fleetPreview, 'data' => array_slice($filtered, ($page - 1) * 12, 12), 'total' => count($filtered), 'page' => $page, 'last_page' => $last, 'today' => $today->toDateString(), 'generated_at' => now()->toIso8601String()]);
        });
    }
}
