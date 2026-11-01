<?php
namespace App\Http\Middleware;
use App\Support\Finance\Expenses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class RequireFinancialManager {
 public function handle(Request $request, Closure $next): Response {
  app(Expenses::class)->actor($request->user()->id);
  return $next($request);
 }
}
