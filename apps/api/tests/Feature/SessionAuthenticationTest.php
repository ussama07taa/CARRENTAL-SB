<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function responsible(): CompanyMembership
    {
        $company = Company::factory()->create(['name' => 'Agence Démo']);

        return Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create([
            'user_id' => User::factory()->create(['name' => 'Responsable Démo', 'email' => 'demo@example.test'])->id,
        ]));
    }

    public function test_login_normalizes_email_and_rotates_session_and_csrf_token(): void
    {
        $membership = $this->responsible();
        $this->withSession(['_token' => 'old-token', 'company_id' => 999]);
        $oldId = session()->getId();
        $this->postJson('/api/v1/auth/login', ['email' => ' DEMO@EXAMPLE.TEST ', 'password' => 'password'])
            ->assertNoContent()->assertSessionHas('company_id', $membership->company_id);
        $this->assertAuthenticatedAs($membership->user);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame('old-token', session()->token());
    }

    public function test_wrong_password_and_unknown_account_have_the_same_error(): void
    {
        $this->responsible();
        $first = $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.test', 'password' => 'incorrect'])->assertUnauthorized();
        $second = $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertUnauthorized();
        $this->assertSame($first->json(), $second->json());
        $this->assertGuest();
    }

    public function test_sixth_login_attempt_is_throttled_and_recovers_after_a_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => ' MISSING@EXAMPLE.TEST ', 'password' => 'incorrect'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertUnauthorized();
    }

    public function test_login_rejects_missing_fields_and_cannot_accept_company_from_browser(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.test', 'password' => 'password', 'company_id' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->assertGuest();
    }

    public function test_valid_credentials_without_a_single_company_do_not_create_an_authenticated_session(): void
    {
        User::factory()->create(['email' => 'demo@example.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.test', 'password' => 'password'])
            ->assertForbidden()->assertSessionMissing('company_id');
        $this->assertGuest();
    }

    public function test_multiple_memberships_require_explicit_future_selection_rather_than_guessing(): void
    {
        $membership = $this->responsible();
        $other = Company::factory()->create();
        Tenant::run($other->id, fn () => CompanyMembership::factory()->create(['user_id' => $membership->user_id]));
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.test', 'password' => 'password'])->assertForbidden();
        $this->assertGuest();
    }

    public function test_private_identity_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_private_identity_contains_only_names_from_the_authenticated_agency(): void
    {
        $membership = $this->responsible();
        $this->actingAs($membership->user)->withSession(['company_id' => $membership->company_id])
            ->getJson('/api/v1/auth/me')->assertOk()->assertExactJson([
                'user' => ['name' => 'Responsable Démo'],
                'company' => ['name' => 'Agence Démo'],
                'responsible' => ['name' => 'Responsable Démo'],
                'demo' => false,
            ])->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_missing_company_context_is_forbidden(): void
    {
        $membership = $this->responsible();
        $this->actingAs($membership->user)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_other_company_in_session_does_not_leak_its_name(): void
    {
        $membership = $this->responsible();
        $other = Company::factory()->create(['name' => 'Private other agency']);
        $this->actingAs($membership->user)->withSession(['company_id' => $other->id])
            ->getJson('/api/v1/auth/me')->assertForbidden()->assertDontSee('Private other agency');
    }

    public function test_revoked_membership_is_checked_again_for_existing_sessions(): void
    {
        $company = Company::factory()->create();
        $member = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        DB::table('company_memberships')->where('id', $member->id)->delete();
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_logout_invalidates_session_and_private_endpoint_becomes_unauthorized(): void
    {
        $membership = $this->responsible();
        $this->actingAs($membership->user)->withSession(['company_id' => $membership->company_id, '_token' => 'old-token']);
        $oldId = session()->getId();
        $this->postJson('/api/v1/auth/logout')->assertNoContent()->assertSessionMissing('company_id');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame('old-token', session()->token());
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_and_logout_reject_cross_site_posts_without_csrf(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeader('Origin', 'https://untrusted.example')->postJson('/api/v1/auth/login', [
            'email' => 'demo@example.test', 'password' => 'password',
        ])->assertStatus(419);
        $this->withHeader('Origin', 'https://untrusted.example')->postJson('/api/v1/auth/logout')->assertStatus(419);
    }

    public function test_demo_setup_is_refused_outside_the_isolated_demo_environment(): void
    {
        $this->artisan('company:demo')->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_rejected_csrf_logout_does_not_destroy_the_existing_session(): void
    {
        $membership = $this->responsible();
        $this->actingAs($membership->user)->withSession(['company_id' => $membership->company_id, '_token' => 'real-token']);
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeader('X-CSRF-TOKEN', 'wrong-token')->postJson('/api/v1/auth/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($membership->user);
        $this->assertSame($membership->company_id, session('company_id'));
    }
}
