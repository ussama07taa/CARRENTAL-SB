<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationSecurityTest extends TestCase
{
    public function test_horizon_is_not_public_even_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->get('/horizon')->assertForbidden();
    }

    public function test_csrf_cookie_is_available_to_the_dashboard(): void
    {
        $this->withHeader('Origin', 'http://localhost:3000')
            ->get('/sanctum/csrf-cookie')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_another_origin_is_not_granted_credentialed_access(): void
    {
        // The CORS library emits the sole configured origin as a static header.
        // It must never reflect the untrusted origin or use a wildcard.
        $this->withHeader('Origin', 'https://untrusted.example')
            ->get('/sanctum/csrf-cookie')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }
}
