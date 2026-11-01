<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePurchasePaymentRequest;
use App\Support\Vehicles\VehicleLoans;
use App\Support\Vehicles\VehiclePurchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VehiclePurchasePaymentController extends Controller
{
    public function store(SavePurchasePaymentRequest $request, int $loan, VehicleLoans $loans, VehiclePurchases $purchases, ?int $payment = null): JsonResponse
    {
        $actor = $loans->actor($request->user()->id);
        $input = $request->validated();
        foreach (['amount_centimes', 'expected_remaining_centimes', 'expected_version'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = (int) $input[$field];
            }
        }
        $id = DB::transaction(function () use ($loans, $purchases, $loan, $actor, $input, $payment) {
            $loans->lock();

            return $purchases->save($loans->loan($loan), $actor, $input, $payment);
        });

        return response()->json(['id' => $id], 201);
    }
}
