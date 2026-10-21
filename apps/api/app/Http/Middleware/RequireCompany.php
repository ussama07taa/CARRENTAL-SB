<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->session()->get('company_id');
        abort_unless(is_int($companyId) && $companyId > 0, 403, 'Contexte agence requis.');

        return Tenant::forUser($request->user(), $companyId, fn () => $next($request));
    }
}
