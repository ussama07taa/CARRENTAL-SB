<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\CompanyMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function store(LoginRequest $request): Response|JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->safe()->only(['email', 'password']))) {
            return response()->json(['message' => 'Adresse e-mail ou mot de passe incorrect.'], 401);
        }
        $memberships = DB::table('company_memberships')->where('user_id', Auth::id())->limit(2)->get();
        if ($memberships->count() !== 1) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Accès à l’agence indisponible. Contactez le responsable.'], 403);
        }
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $request->session()->put('company_id', (int) $memberships->sole()->company_id);

        return response()->noContent();
    }

    public function show(Request $request): JsonResponse
    {
        $membership = CompanyMembership::query()->where('user_id', $request->user()->id)->with('company')->sole();
        $responsible = CompanyMembership::query()->where('is_primary_responsible', true)->with('user')->first();
        abort_unless($responsible, 403, 'Responsable de l’agence indisponible.');

        return response()->json([
            'user' => ['name' => $request->user()->name],
            'company' => ['name' => $membership->company->name],
            'responsible' => ['name' => $responsible->user->name],
            'demo' => app()->environment('demo'),
        ]);
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
