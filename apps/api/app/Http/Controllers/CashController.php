<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCashCountRequest;
use App\Http\Requests\SaveCashEntryRequest;
use App\Support\Finance\CashRegister;
use App\Support\Finance\Expenses;
use App\Support\Finance\FinancialSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashController extends Controller
{
    public function index(Request $request, CashRegister $cash, Expenses $access): JsonResponse
    {
        $access->actor($request->user()->id);
        $input = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString(), Rule::when($request->filled('from'), 'after_or_equal:from')], 'channel' => ['nullable', Rule::in(['cash', 'noncash'])], 'direction' => ['nullable', Rule::in(['all', 'in', 'out'])], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        abort_if(($input['from'] ?? '') > ($input['to'] ?? now('Africa/Casablanca')->toDateString()), 422, 'La date de début dépasse la fin de période.');

        return response()->json(FinancialSnapshot::read(fn () => $cash->index($input)));
    }

    public function entry(Request $request, CashRegister $cash, Expenses $access, int $entry): JsonResponse
    {
        $access->actor($request->user()->id);

        return response()->json($cash->entry($entry));
    }

    public function save(SaveCashEntryRequest $request, CashRegister $cash, Expenses $access, ?int $entry = null): JsonResponse
    {
        $id = $cash->saveEntry($access->actor($request->user()->id), $request->validated(), $entry);

        return response()->json(['id' => $id], 201);
    }

    public function count(SaveCashCountRequest $request, CashRegister $cash, Expenses $access): JsonResponse
    {
        $id = $cash->count($access->actor($request->user()->id), $request->validated());

        return response()->json(['id' => $id], 201);
    }
}
