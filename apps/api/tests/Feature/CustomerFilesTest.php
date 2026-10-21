<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerFilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function member(): CompanyMembership
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
    }

    private function login(CompanyMembership $member): void
    {
        $this->actingAs($member->user)->withSession(['company_id' => $member->company_id]);
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['first_name' => 'Amine', 'last_name' => 'Exemple', 'phone' => '0612345678', 'creation_key' => (string) Str::uuid()], $overrides);
    }

    private function client(CompanyMembership $member, array $values = []): Customer
    {
        return Tenant::run($member->company_id, fn () => Customer::factory()->create($values));
    }

    public function test_guest_and_missing_company_cannot_read_or_write_clients(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers', $this->input())->assertUnauthorized();
        $member = $this->member();
        $this->actingAs($member->user)->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_creation_requires_names_and_phone_and_rejects_tenant_injection(): void
    {
        $this->login($this->member());
        $this->postJson('/api/v1/customers', [])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'phone']);
        $this->postJson('/api/v1/customers', $this->input(['company_id' => 99]))->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/customers', $this->input(['phone' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_minimal_creation_normalizes_phone_and_atomically_records_actor(): void
    {
        $member = $this->member();
        $this->login($member);
        $response = $this->postJson('/api/v1/customers', $this->input(['first_name' => ' Amine ']))->assertCreated()
            ->assertJsonPath('data.first_name', 'Amine')->assertJsonPath('data.phone', '+212612345678')
            ->assertJsonPath('data.language', 'fr')->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.company_id')->assertJsonMissingPath('data.creation_hash');
        $this->assertDatabaseHas('customer_events', ['customer_id' => $response->json('data.id'), 'company_id' => $member->company_id, 'actor_membership_id' => $member->id, 'action' => 'created']);
        $this->getJson('/api/v1/customers/'.$response->json('data.id'))->assertOk()->assertJsonPath('activity.0.action', 'created');
    }

    public function test_retry_is_idempotent_and_reusing_key_with_different_data_is_rejected(): void
    {
        $this->login($this->member());
        $input = $this->input();
        $first = $this->postJson('/api/v1/customers', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers', $input)->assertCreated()->assertJsonPath('data.id', $first);
        $this->postJson('/api/v1/customers', array_replace($input, ['first_name' => 'Other']))->assertConflict()->assertJsonPath('code', 'REQUEST_ALREADY_USED');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_events', 1);
    }

    public function test_same_phone_warns_but_explicitly_confirmed_different_person_is_allowed(): void
    {
        $member = $this->member();
        $this->client($member, ['phone' => '+212612345678']);
        $this->login($member);
        $input = $this->input();
        $this->postJson('/api/v1/customers', $input)->assertConflict()->assertJsonPath('code', 'POSSIBLE_DUPLICATE')->assertJsonCount(1, 'duplicates');
        $this->postJson('/api/v1/customers', [...$input, 'acknowledge_duplicates' => true])->assertCreated();
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_case_insensitive_same_name_warns_even_with_different_phone(): void
    {
        $member = $this->member();
        $this->client($member, ['first_name' => 'AMINE', 'last_name' => 'EXEMPLE', 'phone' => '+212600000999']);
        $this->login($member);
        $this->postJson('/api/v1/customers', $this->input())->assertConflict()->assertJsonPath('code', 'POSSIBLE_DUPLICATE');
    }

    public function test_identity_collision_is_blocked_even_after_duplicate_acknowledgement(): void
    {
        $member = $this->member();
        $existing = $this->client($member, ['identity_type' => 'cin', 'identity_number' => 'AB12345']);
        $this->login($member);
        $this->postJson('/api/v1/customers', $this->input(['identity_type' => 'cin', 'identity_number' => 'ab 12345', 'acknowledge_duplicates' => true]))
            ->assertConflict()->assertJsonPath('code', 'IDENTITY_ALREADY_USED')->assertJsonPath('customer_id', $existing->id);
        $this->postJson('/api/v1/customers', $this->input(['identity_number' => 'AB12345']))->assertUnprocessable()->assertJsonValidationErrors('identity_type');
    }

    public function test_other_agencies_cannot_be_read_modified_or_discovered_via_duplicates(): void
    {
        $mine = $this->member();
        $other = $this->member();
        $secret = $this->client($other, ['first_name' => 'Private', 'identity_type' => 'cin', 'identity_number' => 'AB12345', 'phone' => '+212612345678']);
        $this->login($mine);
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data')->assertDontSee('Private');
        $this->getJson('/api/v1/customers/'.$secret->id)->assertNotFound();
        $input = $this->input(['identity_type' => 'cin', 'identity_number' => 'AB12345']);
        unset($input['creation_key']);
        $this->putJson('/api/v1/customers/'.$secret->id, [...$input, 'version' => 1])->assertNotFound();
        $this->postJson('/api/v1/customers', $this->input(['identity_type' => 'cin', 'identity_number' => 'AB12345']))->assertCreated();
        $this->assertDatabaseHas('customers', ['id' => $secret->id, 'first_name' => 'Private']);
    }

    public function test_search_by_name_phone_identity_and_reference_returns_only_summary(): void
    {
        $member = $this->member();
        $client = $this->client($member, ['first_name' => 'Amine', 'last_name' => 'Exemple', 'phone' => '+212612345678', 'identity_type' => 'cin', 'identity_number' => 'AB12345']);
        $this->login($member);
        foreach (['amine exemple', 'Exemple Amine', '0612345678', '+212612345678', 'ab 12345', $client->reference] as $search) {
            $this->getJson('/api/v1/customers?q='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $client->id)->assertJsonMissingPath('data.0.identity_number')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        }
        $this->getJson('/api/v1/customers?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customers?q=missing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pagination_is_bounded_and_newest_first(): void
    {
        $member = $this->member();
        Tenant::run($member->company_id, fn () => Customer::factory()->count(17)->create());
        $this->login($member);
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('total', 17)->assertJsonPath('last_page', 2);
        $this->getJson('/api/v1/customers?page=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_update_preserves_audit_and_stale_edits_do_not_overwrite(): void
    {
        $member = $this->member();
        $client = $this->client($member);
        $this->login($member);
        $input = $this->input(['city' => 'Rabat']);
        unset($input['creation_key']);
        $input['version'] = 1;
        $this->putJson('/api/v1/customers/'.$client->id, $input)->assertOk()->assertJsonPath('data.city', 'Rabat')->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/customers/'.$client->id, [...$input, 'city' => 'Tanger'])->assertConflict()->assertJsonPath('code', 'CUSTOMER_CHANGED');
        $this->assertDatabaseHas('customers', ['id' => $client->id, 'city' => 'Rabat', 'version' => 2]);
        $event = DB::table('customer_events')->where('customer_id', $client->id)->sole();
        $this->assertSame('Rabat', json_decode($event->after, true)['city']);
        $this->assertSame($member->id, $event->actor_membership_id);
        $this->assertDatabaseCount('customer_events', 1);
    }

    public function test_update_can_clear_optional_fields_without_changing_identity_of_record(): void
    {
        $member = $this->member();
        $client = $this->client($member, ['city' => 'Rabat', 'identity_type' => 'cin', 'identity_number' => 'AB12345']);
        $this->login($member);
        $input = $this->input();
        unset($input['creation_key']);
        $this->putJson('/api/v1/customers/'.$client->id, [...$input, 'version' => 1])->assertOk()->assertJsonPath('data.city', null)->assertJsonPath('data.identity_number', null)->assertJsonPath('data.reference', $client->reference);
    }

    public function test_database_rejects_duplicate_identity_even_outside_http(): void
    {
        $member = $this->member();
        $this->client($member, ['identity_type' => 'cin', 'identity_number' => 'AB12345']);
        $this->expectException(QueryException::class);
        $this->client($member, ['identity_type' => 'cin', 'identity_number' => 'AB12345']);
    }

    public function test_audit_rows_cannot_be_mutated(): void
    {
        $this->login($this->member());
        $this->postJson('/api/v1/customers', $this->input())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('customer_events')->update(['action' => 'tampered']);
    }

    public function test_audit_actor_cannot_belong_to_another_company(): void
    {
        $mine = $this->member();
        $other = $this->member();
        $client = $this->client($mine);
        $this->expectException(QueryException::class);
        DB::table('customer_events')->insert(['company_id' => $mine->company_id, 'customer_id' => $client->id, 'actor_membership_id' => $other->id, 'action' => 'created', 'after' => '{}']);
    }

    public function test_customer_model_queries_fail_closed_without_tenant(): void
    {
        $this->expectException(HttpException::class);
        Customer::count();
    }

    public function test_no_delete_endpoint_and_csrf_is_required_for_mutations(): void
    {
        $member = $this->member();
        $client = $this->client($member);
        $this->login($member);
        $this->deleteJson('/api/v1/customers/'.$client->id)->assertStatus(405);
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeader('Origin', 'https://outside.example')->postJson('/api/v1/customers', $this->input())->assertStatus(419);
    }

    public function test_demo_seeding_refuses_non_demo_database(): void
    {
        $this->artisan('customers:demo')->assertFailed();
        $this->assertDatabaseCount('customers', 0);
    }
}
