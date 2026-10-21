<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Reservations\FleetPlanning;
use App\Support\Reservations\RentalPayment;
use App\Support\Reservations\RentalSettlement;
use App\Support\Reservations\RequestAvailability;
use App\Support\Reservations\RequestPeriod;
use App\Support\Reservations\TemporaryHold;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function planning(Request $request, FleetPlanning $planning): JsonResponse
    {
        $input = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'days' => ['required', 'integer', 'min:1', 'max:31'], 'q' => ['nullable', 'string', 'max:100'], 'filter' => ['nullable', Rule::in(['all', 'requests', 'garage'])], 'page' => ['nullable', 'integer', 'min:1']]);

        app(TemporaryHold::class)->expire();

        return DB::transaction(function () use ($planning, $input): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json($planning->snapshot($input));
        });
    }

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('reservations')) {
            return response()->json(['code' => 'REQUESTS_NOT_READY', 'message' => 'La création des demandes est en cours de préparation.'], 503);
        }
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = mb_strtolower(trim($input['q'] ?? ''));
        app(TemporaryHold::class)->expire();
        $holdService = app(TemporaryHold::class);
        $holds = $holdService->active();
        $minutes = $holdService->preparationMinutes();
        $holdReady = $holdService->ready();
        $customers = Customer::get()->keyBy('id');
        $vehicles = Vehicle::get()->keyBy('id');
        $items = Reservation::orderByDesc('id')->get()->map(fn (Reservation $row): array => $this->present($row, $customers[$row->customer_id], $vehicles[$row->vehicle_id], $holds, $minutes, $holdReady));
        if ($search !== '') {
            $items = $items->filter(fn (array $row): bool => str_contains(mb_strtolower($row['reference'].' '.implode(' ', $row['customer']).' '.implode(' ', $row['vehicle'])), $search));
        }
        $total = $items->count();
        $last = max(1, (int) ceil($total / 15));
        $page = min((int) ($input['page'] ?? 1), $last);

        $selected = $items->values()->slice(($page - 1) * 15, 15)->values();
        $accounts = app(RentalSettlement::class)->briefs(Reservation::whereIn('id', $selected->pluck('id'))->get());
        $selected = $selected->map(fn ($row) => [...$row, 'account' => $accounts[$row['id']] ?? null]);

        return response()->json(['data' => $selected, 'total' => $total, 'page' => $page, 'last_page' => $last]);
    }

    public function availability(Request $request, RequestPeriod $periods, RequestAvailability $availability): JsonResponse
    {
        $period = $periods->validate($request);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'show' => ['nullable', Rule::in(['available', 'unavailable'])], 'page' => ['nullable', 'integer', 'min:1']]);

        app(TemporaryHold::class)->expire();

        return DB::transaction(function () use ($input, $period, $availability): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $reasons = $availability->blockingReasons($period['starts_at'], $period['ends_at']);
            $blocked = array_keys($reasons);
            $search = mb_strtolower(trim($input['q'] ?? ''));
            $vehicles = Vehicle::orderByDesc('id')->get()->filter(fn (Vehicle $vehicle): bool => $search === '' || str_contains(mb_strtolower($vehicle->plate.' '.$vehicle->make.' '.$vehicle->model), $search));
            $unavailable = $vehicles->filter(fn (Vehicle $vehicle): bool => in_array($vehicle->id, $blocked, true));
            $available = $vehicles->reject(fn (Vehicle $vehicle): bool => in_array($vehicle->id, $blocked, true));
            $items = ($input['show'] ?? 'available') === 'unavailable' ? $unavailable : $available;
            $last = max(1, (int) ceil($items->count() / 12));
            $page = min((int) ($input['page'] ?? 1), $last);
            $selected = $items->values()->slice(($page - 1) * 12, 12);
            $covers = VehiclePhoto::whereIn('vehicle_id', $selected->pluck('id'))->where('slot', 'cover')->orderByDesc('version')->get()->unique('vehicle_id')->keyBy('vehicle_id');
            $unready = app(\App\Support\Reservations\RentalReturn::class)->unreadyVehicles();
            $rows = $selected->map(fn (Vehicle $vehicle): array => [...$vehicle->only(['id', 'plate', 'make', 'model', 'year', 'current_odometer']), 'readiness_warning' => in_array($vehicle->id, $unready, true) ? 'Retour précédent : disponibilité après contrôle à valider avant la remise des clés.' : null, 'cover_id' => $covers->get($vehicle->id)?->id, 'available' => ! in_array($vehicle->id, $blocked, true), 'reason' => $reasons[$vehicle->id] ?? null])->values();

            return response()->json(['data' => $rows, 'available_count' => $available->count(), 'unavailable_count' => $unavailable->count(), 'total' => $items->count(), 'page' => $page, 'last_page' => $last, 'period' => $period, 'checked_at' => now()->toIso8601String()]);
        });
    }

    public function store(Request $request, RequestPeriod $periods, RequestAvailability $availability): JsonResponse
    {
        $input = $request->validate(['customer_id' => ['required', 'integer', 'min:1'], 'vehicle_id' => ['required', 'integer', 'min:1'], 'starts_local' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_local' => ['required', 'date_format:Y-m-d\TH:i'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'actor_membership_id' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'integer' => 'Choisissez un fichier proposé.', 'date_format' => 'Saisissez une date et une heure valides.', 'prohibited' => 'Ce champ ne peut pas être fourni.']);
        $values = ['customer_id' => (int) $input['customer_id'], 'vehicle_id' => (int) $input['vehicle_id'], 'starts_local' => $input['starts_local'], 'ends_local' => $input['ends_local']];
        $hash = hash('sha256', json_encode($values));

        return DB::transaction(function () use ($request, $periods, $availability, $input, $values, $hash): JsonResponse {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $old = Reservation::where('request_key', $input['request_key'])->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $hash)) {
                    return response()->json(['code' => 'REQUEST_ALREADY_USED', 'message' => 'Cette demande est déjà enregistrée. Ouvrez son fichier.', 'id' => $old->id], 409);
                }

                return response()->json(['data' => $this->present($old)], 200);
            }
            $period = $periods->validate($request);
            $request->validate(['customer_id' => [Rule::exists('customers', 'id')->where('company_id', Tenant::id())], 'vehicle_id' => [Rule::exists('vehicles', 'id')->where('company_id', Tenant::id())]], ['exists' => 'Ce fichier est introuvable dans votre agence. Choisissez-le à nouveau.']);
            app(TemporaryHold::class)->expireLocked();
            $reasons = $availability->blockingReasons($period['starts_at'], $period['ends_at']);
            if (isset($reasons[$values['vehicle_id']])) {
                $message = $reasons[$values['vehicle_id']].'. Choisissez une autre voiture.';

                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => $message, 'errors' => ['vehicle_id' => [$message]]], 409);
            }
            $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
            $record = Reservation::create(['customer_id' => $values['customer_id'], 'vehicle_id' => $values['vehicle_id'], 'actor_membership_id' => $actor->id, 'status' => 'demande', 'starts_at' => $period['starts_at'], 'ends_at' => $period['ends_at'], 'request_key' => $input['request_key'], 'request_hash' => $hash]);
            DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $record->id, 'actor_membership_id' => $actor->id, 'action' => 'request_created', 'data' => json_encode([...$values, 'starts_at' => $period['starts_at'], 'ends_at' => $period['ends_at'], 'timezone' => RequestPeriod::TIMEZONE, 'blocks_vehicle' => false]), 'created_at' => now()]);

            return response()->json(['data' => $this->present($record->refresh())], 201);
        });
    }

    public function show(Request $request, int $reservation): JsonResponse
    {
        app(TemporaryHold::class)->expire();
        $record = Reservation::findOrFail($reservation);
        $events = DB::table('reservation_events as e')->join('company_memberships as m', function ($join): void {
            $join->on('m.id', '=', 'e.actor_membership_id')->on('m.company_id', '=', 'e.company_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('e.company_id', Tenant::id())->where('e.reservation_id', $record->id)->orderBy('e.id')->get(['e.action', 'e.data', 'e.created_at', 'u.name as actor']);
        foreach ($events as $event) {
            if (str_starts_with($event->action, 'foreign_')) {
                $event->data = json_encode(collect(json_decode($event->data, true))->only(['reason', 'countries', 'transit_countries', 'starts_local', 'ends_local', 'insurer', 'insurance_reference', 'insurance_starts_on', 'insurance_expires_on', 'request_id', 'issue_id'])->all());
            }
        }

        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => [...$this->present($record), 'account' => app(RentalSettlement::class)->briefs(collect([$record]))[$record->id] ?? null], 'events' => $events, 'financial' => app(RentalPayment::class)->summary($record), 'can_manage_price' => $actor->is_primary_responsible, 'can_verify_transfer' => $actor->is_primary_responsible]);
    }

    public function hold(Request $request, int $reservation, TemporaryHold $holds): JsonResponse
    {
        $input = $request->validate(['request_key' => ['required', 'uuid'], 'agreement_confirmed' => ['required', 'accepted'], 'company_id' => ['prohibited'], 'hold_expires_at' => ['prohibited'], 'preparation_minutes' => ['prohibited'], 'status' => ['prohibited']]);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        try {
            $result = $holds->activate($reservation, $input['request_key'], $actor->id);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                return response()->json(['code' => 'UNIT_UNAVAILABLE', 'message' => 'Cette voiture vient d’être bloquée sur ces dates. Actualisez le dossier.'], 409);
            }
            throw $exception;
        }
        if (isset($result['error'])) {
            return response()->json(['code' => $result['error'], 'message' => $result['message'], 'conflicts' => $result['conflicts'] ?? []], $result['status']);
        }

        return response()->json(['data' => $this->present($result['record']), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    private function present(Reservation $record, ?Customer $customer = null, ?Vehicle $vehicle = null, ?Collection $holds = null, ?int $minutes = null, ?bool $holdReady = null): array
    {
        $customer ??= Customer::findOrFail($record->customer_id);
        $vehicle ??= Vehicle::findOrFail($record->vehicle_id);

        $hold = app(TemporaryHold::class);
        $blocks = in_array($record->status, ['confirmee', 'en_location'], true) || ($record->status === 'temporaire' && $record->hold_expires_at > now());
        $conflicts = $hold->conflicts($record, $holds, $minutes);

        return ['active_extension_id' => $record->active_extension_id, 'active_swap_id' => $record->active_swap_id, 'assignment_starts_local' => $record->assignment_starts_at?->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'has_departed' => $record->active_swap_id !== null || in_array($record->status, ['en_location', 'retournee'], true), 'hold_ready' => $holdReady ?? $hold->ready(), 'hold_expires_at' => $record->hold_expires_at?->toIso8601String(), 'blocked_until_local' => $record->blocked_until?->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'preparation_minutes' => $record->preparation_minutes, 'conflicts' => $conflicts, 'hold_expired' => $record->status === 'demande' && $record->hold_expires_at !== null, 'id' => $record->id, 'reference' => 'DEM-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT), 'status' => in_array($record->status, ['confirmee', 'en_location', 'retournee', 'annulee'], true) ? $record->status : ($blocks ? 'temporaire' : 'demande'), 'blocks_vehicle' => $blocks, 'overdue' => $record->status === 'en_location' && $record->ends_at <= now(), 'vehicle_overdue' => Reservation::where('vehicle_id', $record->vehicle_id)->where('id', '<>', $record->id)->where('status', 'en_location')->where('ends_at', '<=', now())->exists(), 'customer' => $customer->only(['id', 'first_name', 'last_name', 'phone']), 'vehicle' => $vehicle->only(['id', 'plate', 'make', 'model']), 'starts_local' => $record->starts_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'ends_local' => $record->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'), 'timezone' => RequestPeriod::TIMEZONE, 'duration_minutes' => (int) $record->starts_at->diffInMinutes($record->ends_at), 'created_at' => $record->created_at->toIso8601String()];
    }
}
