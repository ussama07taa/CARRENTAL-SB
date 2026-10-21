<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Vehicles\DamageTickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DamageTicketController extends Controller
{
    private function actor(Request $request): CompanyMembership
    {
        abort_unless(Schema::hasTable('damage_tickets'), 503, 'Le suivi des dommages attend son activation.');

        return CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function index(Request $request, DamageTickets $service)
    {
        $actor = $this->actor($request);
        $input = $request->validate(['vehicle_id' => ['nullable', 'integer', 'min:1'], 'inspection_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'open' => ['nullable', 'boolean']]);
        $context = null;
        if (! empty($input['inspection_id'])) {
            $context = $service->inspection($input['inspection_id']);
            $input['vehicle_id'] = $context->vehicle_id;
        }
        $selectedVehicle = ! empty($input['vehicle_id']) ? Vehicle::findOrFail($input['vehicle_id']) : null;
        $rows = $service->query()->when(! empty($input['vehicle_id']), fn ($q) => $q->where('vehicle_id', $input['vehicle_id']))->when(! empty($input['q']), fn ($q) => $q->where(function ($q) use ($input) {
            $q->where('title', 'ilike', '%'.$input['q'].'%')->orWhere('zone', 'ilike', '%'.$input['q'].'%');
        }))->when($request->boolean('open'), fn ($q) => $q->where('closed', false))->orderBy('closed')->orderBy('follow_up_on')->orderByDesc('id')->paginate(20);
        $vehicles = Vehicle::whereIn('id', $rows->pluck('vehicle_id'))->get()->keyBy('id');
        $rows->getCollection()->transform(fn ($row) => (object) [...(array) $row, 'vehicle_label' => ($vehicles[$row->vehicle_id]->make ?? '').' '.($vehicles[$row->vehicle_id]->model ?? ''), 'plate' => $vehicles[$row->vehicle_id]->plate ?? '']);

        return response()->json(['data' => $rows->items(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'selected_vehicle' => $selectedVehicle?->only(['id', 'make', 'model', 'plate']), 'context' => $context ? ['inspection_id' => $context->id, 'vehicle_id' => $context->vehicle_id, 'reservation_id' => $context->reservation_id, 'return_id' => $context->return_id, 'notes' => $context->notes] : null, 'owners' => CompanyMembership::with('user')->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name]), 'actor_id' => $actor->id]);
    }

    public function show(Request $request, int $ticket, DamageTickets $service)
    {
        $actor = $this->actor($request);

        return DB::transaction(function () use ($ticket, $actor, $service) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json($service->show($ticket, $actor));
        });
    }

    public function store(Request $request, DamageTickets $service, ?int $ticket = null)
    {
        $actor = $this->actor($request);
        $base = $request->validate(['kind' => ['required', Rule::in($ticket ? ['followup', 'observe', 'maintenance', 'decision', 'insurance', 'receipt', 'receipt_void', 'close', 'reopen'] : ['create'])]]);
        $rules = [
            'create' => ['vehicle_id' => ['required', 'integer', 'min:1'], 'inspection_id' => ['nullable', 'integer', 'min:1'], 'title' => ['required', 'string', 'min:3', 'max:160'], 'zone' => ['required', 'string', 'min:2', 'max:100'], 'description' => ['required', 'string', 'min:3', 'max:4000'], 'occurred_local' => ['required', 'date_format:Y-m-d\TH:i'], 'owner_membership_id' => ['required', 'integer', 'min:1'], 'follow_up_on' => ['required', 'date_format:Y-m-d'], 'next_action' => ['required', 'string', 'min:3', 'max:250']],
            'followup' => ['owner_membership_id' => ['required', 'integer', 'min:1'], 'follow_up_on' => ['required', 'date_format:Y-m-d'], 'next_action' => ['required', 'string', 'min:3', 'max:250'], 'mobility' => ['sometimes', Rule::in(['review', 'blocked', 'usable'])], 'repair' => ['sometimes', Rule::in(['review', 'deferred', 'garage', 'done'])], 'checked' => ['sometimes', 'accepted']],
            'observe' => ['inspection_id' => ['required', 'integer', 'min:1'], 'observation_kind' => ['required', Rule::in(['known', 'worsened'])]],
            'maintenance' => ['maintenance_id' => ['required', 'integer', 'min:1']],
            'decision' => ['reservation_id' => ['required', 'integer', 'min:1'], 'responsibility' => ['required', Rule::in(['review', 'customer', 'third_party', 'shared', 'disputed', 'none'])], 'amount_centimes' => ['present', 'nullable', 'integer', 'between:0,1000000000'], 'evidence_reference' => ['required', 'string', 'min:3', 'max:1000'], 'checked' => ['required', 'accepted']],
            'insurance' => ['insurer' => ['required', 'string', 'max:160'], 'reference' => ['required', 'string', 'max:160'], 'requested_centimes' => ['required', 'integer', 'between:0,1000000000'], 'approved_centimes' => ['present', 'nullable', 'integer', 'between:0,1000000000'], 'evidence_reference' => ['required', 'string', 'min:3', 'max:1000']],
            'receipt' => ['amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'method' => ['required', Rule::in(['transfer', 'cash', 'cheque'])], 'reference' => ['required', 'string', 'min:3', 'max:160'], 'checked' => ['required', 'accepted']],
            'receipt_void' => ['entry_id' => ['required', 'integer', 'min:1']],
            'close' => ['checked' => ['required', 'accepted']], 'reopen' => [],
        ];
        $input = $request->validate([...$rules[$base['kind']], 'kind' => ['required'], 'request_key' => ['required', 'uuid'], 'expected_version' => [$ticket ? 'required' : 'nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'company_id' => ['prohibited']]);
        if ($base['kind'] === 'decision') {
            abort_if(in_array($input['responsibility'], ['review', 'disputed'], true) && $input['amount_centimes'] !== null, 422, 'Une responsabilité en cours de revue ne fixe pas de montant.');
            abort_if($input['responsibility'] === 'none' && $input['amount_centimes'] !== 0, 422, 'Sans obligation client, le montant approuvé doit être zéro.');
        }

        return response()->json(['id' => $service->save($actor,$ticket,$input)], 201);
    }
}
