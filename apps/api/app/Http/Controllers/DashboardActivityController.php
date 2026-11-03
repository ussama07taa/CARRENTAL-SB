<?php

namespace App\Http\Controllers;

use App\Support\Rentals\DashboardActivity;
use App\Support\Vehicles\MaintenanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardActivityController extends Controller
{
    public function index(Request $request, DashboardActivity $activity, MaintenanceAccess $access): JsonResponse
    {
        $financial = $access->financial($request->user()->id);
        $input = $request->validate([
            'group' => ['nullable', Rule::in(['all', 'departures', 'returns', 'fleet', 'deadlines', 'requests', 'finance'])],
            'window' => ['nullable', Rule::in(['all', 'priority', 'upcoming', 'urgent', 'action'])],
            'notifications' => ['sometimes', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(! $financial && ($input['group'] ?? '') === 'finance', 403, 'Le suivi financier est réservé au responsable.');

        return DB::transaction(function () use ($activity, $input, $financial): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json($activity->read($input, $financial));
        });
    }
}
