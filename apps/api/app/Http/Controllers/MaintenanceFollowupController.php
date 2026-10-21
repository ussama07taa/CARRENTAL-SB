<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMaintenanceFollowupRequest;
use App\Support\Vehicles\MaintenanceFollowups;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;

class MaintenanceFollowupController extends Controller
{
    public function store(SaveMaintenanceFollowupRequest $request, int $vehicle, int $entry, string $item, MaintenanceFollowups $followups, VehicleMaintenance $maintenance): JsonResponse
    {
        $actor = $maintenance->actor($request->user()->id);

        return response()->json(['id' => $followups->save($vehicle, $entry, $item, $actor, $request->validated())], 201);
    }
}
