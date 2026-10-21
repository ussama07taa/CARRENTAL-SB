<?php

namespace Tests\Feature;

use App\Actions\Companies\CreateCompanyWithResponsible;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CompanyFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createCompany(): Company
    {
        return app(CreateCompanyWithResponsible::class)->handle('Agence Test', 'Responsable Test', 'Owner@Example.test', 'A-strong-test-password');
    }

    public function test_setup_creates_linked_responsible_with_hashed_password_and_history(): void
    {
        $this->freezeTime();
        $company = $this->createCompany();
        $user = User::query()->sole();
        $this->assertSame('owner@example.test', $user->email);
        $this->assertTrue(Hash::check('A-strong-test-password', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertNull($user->email_verified_at);
        Tenant::forUser($user, $company->id, function () use ($company, $user): void {
            $membership = $company->memberships()->sole();
            $this->assertTrue($membership->is_primary_responsible);
            $this->assertTrue($membership->user->is($user));
            $this->assertDatabaseHas('company_setup_events', [
                'company_id' => $company->id, 'responsible_membership_id' => $membership->id,
                'source' => 'operator_cli', 'created_at' => now(),
                'operator_name' => posix_getpwuid(posix_geteuid())['name'],
            ]);
        });
    }

    public function test_repeated_setup_does_not_duplicate_or_change_existing_credentials(): void
    {
        $this->createCompany();
        $hash = User::query()->sole()->password;
        try {
            $this->createCompany();
            $this->fail('Repeated setup must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_name', $exception->errors());
        }
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_setup_events', 1);
        $this->assertSame($hash, User::query()->sole()->password);
    }

    public function test_existing_email_is_not_silently_reassigned_to_a_new_agency(): void
    {
        $user = User::factory()->create(['email' => 'OWNER@example.test']);
        try {
            $this->createCompany();
            $this->fail('Existing account must not be claimed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public static function invalidDetails(): array
    {
        return [
            'blank company' => ['', 'Responsable', 'valid@example.test', 'long-test-password', 'company_name'],
            'blank person' => ['Agence', ' ', 'valid@example.test', 'long-test-password', 'responsible_name'],
            'invalid email' => ['Agence', 'Responsable', 'not-an-email', 'long-test-password', 'email'],
            'short password' => ['Agence', 'Responsable', 'valid@example.test', 'short', 'password'],
            'bcrypt byte limit' => ['Agence', 'Responsable', 'valid@example.test', str_repeat('é', 40), 'password'],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_leave_no_partial_records(string $company, string $name, string $email, string $password, string $field): void
    {
        try {
            app(CreateCompanyWithResponsible::class)->handle($company, $name, $email, $password);
            $this->fail('Invalid input must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_memberships', 0);
    }

    public function test_a_failure_after_user_creation_rolls_back_the_whole_setup(): void
    {
        CompanyMembership::creating(function (): void {
            throw new RuntimeException('Simulated storage failure');
        });
        try {
            $this->createCompany();
            $this->fail('The storage failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated storage failure', $exception->getMessage());
        } finally {
            CompanyMembership::clearBootedModels();
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_setup_events', 0);
        $this->expectException(HttpException::class);
        Tenant::id();
    }

    public function test_read_without_tenant_context_is_forbidden(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Contexte agence requis.');
        CompanyMembership::query()->count();
    }

    public function test_write_without_tenant_context_is_forbidden(): void
    {
        $membership = new CompanyMembership;
        $membership->user_id = User::factory()->create()->id;
        $this->expectException(HttpException::class);
        $membership->save();
    }

    public function test_user_cannot_select_another_company_and_queries_hide_other_memberships(): void
    {
        $company = $this->createCompany();
        $user = User::query()->sole();
        $other = Company::factory()->create();
        $otherMembership = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        Tenant::forUser($user, $company->id, function () use ($otherMembership): void {
            $this->assertSame(1, CompanyMembership::query()->count());
            $this->assertNull(CompanyMembership::query()->find($otherMembership->id));
        });
        try {
            Tenant::forUser($user, $other->id, fn () => $this->fail('Must never execute.'));
            $this->fail('Cross-agency context must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_preloaded_membership_cannot_be_saved_in_another_tenant(): void
    {
        $company = $this->createCompany();
        $membership = Tenant::run($company->id, fn () => CompanyMembership::query()->sole());
        $other = Company::factory()->create();
        $this->expectException(HttpException::class);
        Tenant::run($other->id, fn () => $membership->save());
    }

    public function test_nested_context_restores_tenant_and_spatie_team_after_failure(): void
    {
        $first = Company::factory()->create();
        $second = Company::factory()->create();
        Tenant::run($first->id, function () use ($first, $second): void {
            try {
                Tenant::run($second->id, function () use ($second): void {
                    $this->assertSame($second->id, Tenant::id());
                    $this->assertSame($second->id, getPermissionsTeamId());
                    throw new RuntimeException('Failed job');
                });
            } catch (RuntimeException) {
                $this->assertSame($first->id, Tenant::id());
                $this->assertSame($first->id, getPermissionsTeamId());
            }
        });
        $this->assertNull(getPermissionsTeamId());
        $this->expectException(HttpException::class);
        Tenant::id();
    }

    public function test_database_rejects_a_second_primary_responsible(): void
    {
        $company = $this->createCompany();
        $this->expectException(QueryException::class);
        Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
    }

    public function test_audited_responsible_must_belong_to_the_same_agency(): void
    {
        $company = $this->createCompany();
        $other = Company::factory()->create();
        $membership = Tenant::run($company->id, fn () => CompanyMembership::query()->sole());
        $this->expectException(QueryException::class);
        DB::table('company_setup_events')->insert([
            'company_id' => $other->id, 'responsible_membership_id' => $membership->id,
            'source' => 'operator_cli', 'created_at' => now(),
            'operator_name' => 'test-operator',
        ]);
    }

    public function test_history_cannot_be_rewritten_in_the_database(): void
    {
        $this->createCompany();
        $this->expectException(QueryException::class);
        DB::table('company_setup_events')->update(['source' => 'tampered']);
    }

    public function test_setup_command_rejects_noninteractive_password_handling(): void
    {
        $this->artisan('company:setup', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_setup_command_creates_the_account_without_printing_the_password(): void
    {
        $this->artisan('company:setup')
            ->expectsQuestion('Nom de l’agence', 'Agence Test')
            ->expectsQuestion('Nom du responsable', 'Responsable Test')
            ->expectsQuestion('Adresse e-mail du responsable', 'owner@example.test')
            ->expectsQuestion('Mot de passe (12 caractères minimum)', 'A-strong-test-password')
            ->expectsQuestion('Confirmer le mot de passe', 'A-strong-test-password')
            ->doesntExpectOutputToContain('A-strong-test-password')
            ->assertSuccessful();
        $this->assertDatabaseCount('company_memberships', 1);
    }

    public function test_password_confirmation_mismatch_creates_nothing(): void
    {
        $this->artisan('company:setup')
            ->expectsQuestion('Nom de l’agence', 'Agence Test')
            ->expectsQuestion('Nom du responsable', 'Responsable Test')
            ->expectsQuestion('Adresse e-mail du responsable', 'owner@example.test')
            ->expectsQuestion('Mot de passe (12 caractères minimum)', 'A-strong-test-password')
            ->expectsQuestion('Confirmer le mot de passe', 'different-password')
            ->assertFailed();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_default_seeding_does_not_create_an_account_with_a_known_password(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_rejects_case_variant_duplicate_email(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);
        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'OWNER@EXAMPLE.TEST']);
    }

    public function test_membership_cannot_be_reassigned_within_the_current_context(): void
    {
        $company = $this->createCompany();
        $differentUser = User::factory()->create();
        $this->expectException(HttpException::class);
        Tenant::run($company->id, function () use ($differentUser): void {
            $membership = CompanyMembership::query()->sole();
            $membership->user_id = $differentUser->id;
            $membership->save();
        });
    }

    public function test_setup_history_cannot_be_deleted(): void
    {
        $this->createCompany();
        $this->expectException(QueryException::class);
        DB::table('company_setup_events')->delete();
    }

    public function test_nonexistent_company_does_not_establish_a_context(): void
    {
        try {
            Tenant::run(999999, fn () => $this->fail('Unknown company callback must not execute.'));
            $this->fail('Unknown company must be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(HttpException::class);
        Tenant::id();
    }
}
