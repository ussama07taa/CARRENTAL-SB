<?php

namespace App\Http\Controllers;

use App\Support\Finance\Expenses;
use App\Support\Finance\FinancialReports;
use App\Support\Finance\FinancialSnapshot;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    private function input(Request $request, Expenses $access): array
    {
        $access->actor($request->user()->id);
        $input = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:'.now('Africa/Casablanca')->format('Y-m')],
            'vehicle_id' => ['nullable', 'integer', 'min:1'],
            'group' => ['nullable', Rule::in(['activity', 'deposit_damage', 'deposit', 'transfer', 'pending', 'cheque', 'shared'])],
            'direction' => ['nullable', Rule::in(['all', 'in', 'out'])],
            'category' => ['nullable', Rule::in(['rent', 'fuel', 'insurance', 'maintenance', 'expense', 'loan', 'purchase', 'refund'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if (! empty($input['vehicle_id'])) {
            abort_unless(DB::table('vehicles')->where('company_id', Tenant::id())->where('id', $input['vehicle_id'])->exists(), 404);
        }

        return $input;
    }

    public function index(Request $request, Expenses $access, FinancialReports $reports): JsonResponse
    {
        return response()->json(FinancialSnapshot::read(fn () => $reports->index($this->input($request, $access))));
    }

    public function movements(Request $request, Expenses $access, FinancialReports $reports): JsonResponse
    {
        return response()->json(FinancialSnapshot::read(fn () => $reports->movements($this->input($request, $access))));
    }
}
