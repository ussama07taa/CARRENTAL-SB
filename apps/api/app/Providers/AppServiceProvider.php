<?php

namespace App\Providers;

use App\Support\Tenancy\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Tenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('rental-contracts', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()->id.'|'.$request->session()->get('company_id')));
        RateLimiter::for('rental-contract-render-retry', fn (Request $request): Limit => Limit::perMinute(5)->by($request->user()->id.'|'.$request->session()->get('company_id')));
        RateLimiter::for('login', function (Request $request): array {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';
            $response = fn ($request, array $headers) => response()->json([
                'message' => 'Trop de tentatives. Réessayez dans une minute.',
            ], 429, $headers);

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip())->response($response),
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $email.'|'.$request->ip()))->response($response),
            ];
        });
    }
}
