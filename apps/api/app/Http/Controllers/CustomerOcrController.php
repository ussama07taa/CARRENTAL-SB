<?php
namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Support\Customers\DocumentOcr;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CustomerOcrController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json(['allowed' => (bool) config('ocr.enabled') && CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists(), 'message' => config('ocr.enabled') ? 'Lecture réservée au responsable, comme l’accès aux documents privés. Connectez-vous avec le compte responsable.' : 'La lecture automatique est désactivée sur ce serveur. La saisie manuelle reste disponible.']);
    }

    public function __invoke(Request $request, DocumentOcr $ocr): JsonResponse
    {
        // Reuse the existing private customer-document permission; no new role grant.
        abort_unless(CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists(), 403);
        $input = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=100,min_height=100,max_width=4500,max_height=4500'],
            'kind' => ['required', Rule::in(['cin', 'permis'])],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'company_id' => ['prohibited'],
        ]);
        if (isset($input['customer_id'])) Customer::findOrFail($input['customer_id']);
        return response()->json([
            'fields' => $ocr->read(file_get_contents($request->file('image')->getRealPath()), $input['kind']),
            'review_required' => true, 'saved' => false, 'identity_verified' => false,
        ]);
    }
}
