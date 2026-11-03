<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RequestTrash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequestTrashController extends Controller
{
    private function actor(Request $request): CompanyMembership
    {
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($actor->is_primary_responsible, 403, 'Cette action est réservée au responsable.');

        return $actor;
    }

    public function show(Request $request, int $reservation, RequestTrash $trash)
    {
        $this->actor($request);

        return response()->json($trash->eligibility(Reservation::withoutGlobalScope('request_trash')->findOrFail($reservation)));
    }

    public function store(Request $request, int $reservation, RequestTrash $trash)
    {
        $actor = $this->actor($request);
        $input = $request->validate(['action' => ['required', Rule::in(['trash', 'restore', 'cancel_quick'])], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']], ['reason.required' => 'Indiquez le motif.', 'reason.min' => 'Indiquez un motif de 3 caractères minimum.']);

        return response()->json($trash->perform($reservation, $actor, $input));
    }
}
