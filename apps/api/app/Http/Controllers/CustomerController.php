<?php

namespace App\Http\Controllers;

use App\Actions\Customers\SaveCustomer;
use App\Http\Requests\SaveCustomerRequest;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Reservation;
use App\Support\Customers\CustomerFields;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'rental_status' => ['sometimes', 'boolean']]);
        $search = trim($input['q'] ?? '');
        $query = Customer::query();
        if ($search !== '') {
            $literal = fn (string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
            $query->where(function ($query) use ($search, $literal): void {
                $query->whereRaw("(first_name || ' ' || last_name) ILIKE ?", ['%'.$literal($search).'%'])
                    ->orWhereRaw("(last_name || ' ' || first_name) ILIKE ?", ['%'.$literal($search).'%'])
                    ->orWhere('phone', 'like', '%'.$literal(CustomerFields::phone($search)).'%')
                    ->orWhere('identity_number', CustomerFields::identity($search));
                if (preg_match('/^CL-0*([1-9][0-9]{0,17})$/i', $search, $matches)) {
                    $query->orWhere('id', $matches[1]);
                }
            });
        }
        $rows = $query->orderByDesc('id')->paginate(15, ['id', 'first_name', 'last_name', 'phone', 'city', 'updated_at']);
        if ($request->boolean('rental_status')) {
            $renting = Reservation::whereIn('customer_id', $rows->getCollection()->pluck('id'))
                ->where('status', 'en_location')->distinct()->pluck('customer_id')->all();
            $rows->getCollection()->each(fn ($customer) => $customer->setAttribute('in_rental', in_array($customer->id, $renting, true)));
        }

        return response()->json(['data' => $rows->items(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]);
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        $record = Customer::findOrFail($customer);
        $activity = DB::table('customer_events as e')->join('company_memberships as m', function ($join): void {
            $join->on('m.id', '=', 'e.actor_membership_id')->on('m.company_id', '=', 'e.company_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('e.company_id', Tenant::id())->where('e.customer_id', $record->id)->orderByDesc('e.id')->limit(10)
            ->get(['e.action', 'e.created_at', 'u.name as actor']);

        $canManageDocuments = CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists();
        $documents = $canManageDocuments ? CustomerDocument::where('customer_id', $record->id)->orderByDesc('version')->get()->unique('slot')->values() : [];

        return response()->json(['data' => $record, 'activity' => $activity, 'documents' => $documents, 'can_manage_documents' => $canManageDocuments]);
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $save): JsonResponse
    {
        return response()->json(['data' => $save->handle($request->validated(), $request->user()->id)], 201);
    }

    public function update(SaveCustomerRequest $request, int $customer, SaveCustomer $save): JsonResponse
    {
        return response()->json(['data' => $save->handle($request->validated(), $request->user()->id, $customer)]);
    }
}
