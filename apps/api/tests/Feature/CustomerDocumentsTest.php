<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerDocumentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupClient(bool $responsible = true): array
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, fn () => [
            CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]),
            Customer::factory()->create(),
        ]);
    }

    private function login(CompanyMembership $member): void
    {
        $this->actingAs($member->user)->withSession(['company_id' => $member->company_id]);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'image' => $this->image(),
            'slot' => 'identity_front', 'upload_key' => (string) Str::uuid(), 'previous_id' => null,
        ], $overrides);
    }

    private function image(int $width = 6, int $height = 4): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $pixels = str_repeat("\0".str_repeat("\x60\x80\x60", $width), $height);
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($pixels)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('identity.png', $png);
    }

    public function test_upload_is_private_retrievable_and_audited_without_exposing_storage_paths(): void
    {
        Storage::fake('customer_documents');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        $response = $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input())
            ->assertCreated()->assertJsonPath('data.slot', 'identity_front')->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.company_id')->assertJsonMissingPath('data.sha256');
        $id = $response->json('data.id');
        $record = Tenant::run($member->company_id, fn () => CustomerDocument::findOrFail($id));
        Storage::disk('customer_documents')->assertExists($record->path);
        $this->get("/api/v1/customers/{$client->id}/documents/{$id}/content")->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('customer_events', ['company_id' => $member->company_id, 'customer_id' => $client->id, 'action' => 'document_added', 'actor_membership_id' => $member->id]);
        $this->getJson("/api/v1/customers/{$client->id}")->assertJsonCount(1, 'documents')->assertJsonPath('can_manage_documents', true);
    }

    public function test_invalid_and_oversized_files_are_rejected_without_creating_metadata(): void
    {
        Storage::fake('customer_documents');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->image()->size(5121),
            $this->image(6001, 1),
        ] as $file) {
            $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input(['image' => $file]))->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertEmpty(Storage::disk('customer_documents')->allFiles());
    }

    public function test_cross_company_parent_and_missing_membership_are_blocked(): void
    {
        Storage::fake('customer_documents');
        [$owner, $client] = $this->setupClient();
        $this->login($owner);
        $id = $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input())->assertCreated()->json('data.id');
        [$other, $otherClient] = $this->setupClient();
        $this->login($other);
        $this->getJson("/api/v1/customers/{$client->id}/documents/{$id}/content")->assertNotFound();
        $this->getJson("/api/v1/customers/{$otherClient->id}/documents/{$id}/content")->assertNotFound();
        $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input())->assertNotFound();
        $this->assertDatabaseCount('customer_documents', 1);
        $this->withSession(['company_id' => $owner->company_id])->getJson("/api/v1/customers/{$client->id}/documents/{$id}/content")->assertForbidden();
    }

    public function test_unreviewed_employee_document_access_is_not_enabled(): void
    {
        Storage::fake('customer_documents');
        [$owner, $client] = $this->setupClient();
        $this->login($owner);
        $id = $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input())->assertCreated()->json('data.id');
        $employee = Tenant::run($owner->company_id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->login($employee);
        $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input())->assertForbidden();
        $this->getJson("/api/v1/customers/{$client->id}/documents/{$id}/content")->assertForbidden();
        $this->getJson("/api/v1/customers/{$client->id}")->assertOk()->assertJsonCount(0, 'documents')->assertJsonPath('can_manage_documents', false);
    }

    public function test_replacement_preserves_old_version_and_rejects_stale_replacement(): void
    {
        Storage::fake('customer_documents');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        $url = "/api/v1/customers/{$client->id}/documents";
        $first = $this->postJson($url, $this->input())->assertCreated()->json('data.id');
        $second = $this->postJson($url, $this->input(['previous_id' => $first]))->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->postJson($url, $this->input(['previous_id' => $first]))->assertConflict()->assertJsonPath('code', 'DOCUMENT_CHANGED');
        $this->assertDatabaseCount('customer_documents', 2);
        $this->assertCount(2, Storage::disk('customer_documents')->allFiles());
        $this->getJson("/api/v1/customers/{$client->id}")->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.id', $second);
        $this->get("/api/v1/customers/{$client->id}/documents/{$first}/content")->assertOk();
    }

    public function test_retry_does_not_store_a_second_copy_and_conflicting_key_is_rejected(): void
    {
        Storage::fake('customer_documents');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        $input = $this->input();
        $url = "/api/v1/customers/{$client->id}/documents";
        $first = $this->postJson($url, $input)->assertCreated()->json('data.id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.id', $first);
        $this->postJson($url, [...$input, 'slot' => 'licence_front'])->assertConflict()->assertJsonPath('code', 'UPLOAD_KEY_USED');
        $this->assertDatabaseCount('customer_documents', 1);
        $this->assertDatabaseCount('customer_events', 1);
        $this->assertCount(1, Storage::disk('customer_documents')->allFiles());
    }

    public function test_audit_failure_rolls_back_metadata_and_removes_new_blob(): void
    {
        Storage::fake('customer_documents');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_fail_document_audit() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'Simulated audit failure'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_audit_failure BEFORE INSERT ON customer_events FOR EACH ROW EXECUTE FUNCTION test_fail_document_audit();
            SQL);
        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input());
            $this->fail('Expected the audit storage failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Simulated audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertEmpty(Storage::disk('customer_documents')->allFiles());
    }

    public function test_guest_and_cross_site_uploads_are_blocked(): void
    {
        [$member, $client] = $this->setupClient();
        $this->getJson("/api/v1/customers/{$client->id}/documents/1/content")->assertUnauthorized();
        $this->postJson("/api/v1/customers/{$client->id}/documents", $this->input())->assertUnauthorized();
        $this->login($member);
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeader('Origin', 'https://outside.example')->postJson("/api/v1/customers/{$client->id}/documents", $this->input())->assertStatus(419);
    }
}
