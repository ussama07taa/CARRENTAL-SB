<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehiclePhotosTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupClient(bool $responsible = true): array
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, fn () => [
            CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]),
            Vehicle::factory()->create(),
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
            'slot' => 'cover', 'upload_key' => (string) Str::uuid(), 'previous_id' => null,
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
        Storage::fake('vehicle_photos');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        $response = $this->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input())
            ->assertCreated()->assertJsonPath('data.slot', 'cover')->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.company_id')->assertJsonMissingPath('data.sha256');
        $id = $response->json('data.id');
        $record = Tenant::run($member->company_id, fn () => VehiclePhoto::findOrFail($id));
        Storage::disk('vehicle_photos')->assertExists($record->path);
        $this->get("/api/v1/vehicles/{$client->id}/photos/{$id}/content")->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('vehicle_events', ['company_id' => $member->company_id, 'vehicle_id' => $client->id, 'action' => 'photo_added', 'actor_membership_id' => $member->id]);
        $this->getJson("/api/v1/vehicles/{$client->id}")->assertJsonCount(1, 'photos');
    }

    public function test_invalid_and_oversized_files_are_rejected_without_creating_metadata(): void
    {
        Storage::fake('vehicle_photos');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->image()->size(5121),
            $this->image(6001, 1),
        ] as $file) {
            $this->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input(['image' => $file]))->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseCount('vehicle_photos', 0);
        $this->assertEmpty(Storage::disk('vehicle_photos')->allFiles());
    }

    public function test_cross_company_parent_and_missing_membership_are_blocked(): void
    {
        Storage::fake('vehicle_photos');
        [$owner, $client] = $this->setupClient();
        $this->login($owner);
        $id = $this->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input())->assertCreated()->json('data.id');
        [$other, $otherClient] = $this->setupClient();
        $this->login($other);
        $this->getJson("/api/v1/vehicles/{$client->id}/photos/{$id}/content")->assertNotFound();
        $this->getJson("/api/v1/vehicles/{$otherClient->id}/photos/{$id}/content")->assertNotFound();
        $this->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input())->assertNotFound();
        $this->assertDatabaseCount('vehicle_photos', 1);
        $this->withSession(['company_id' => $owner->company_id])->getJson("/api/v1/vehicles/{$client->id}/photos/{$id}/content")->assertForbidden();
    }

    public function test_replacement_preserves_old_version_and_rejects_stale_replacement(): void
    {
        Storage::fake('vehicle_photos');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        $url = "/api/v1/vehicles/{$client->id}/photos";
        $first = $this->postJson($url, $this->input())->assertCreated()->json('data.id');
        $second = $this->postJson($url, $this->input(['previous_id' => $first]))->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->postJson($url, $this->input(['previous_id' => $first]))->assertConflict()->assertJsonPath('code', 'PHOTO_CHANGED');
        $this->assertDatabaseCount('vehicle_photos', 2);
        $this->assertCount(2, Storage::disk('vehicle_photos')->allFiles());
        $this->getJson("/api/v1/vehicles/{$client->id}")->assertJsonCount(1, 'photos')->assertJsonPath('photos.0.id', $second);
        $this->get("/api/v1/vehicles/{$client->id}/photos/{$first}/content")->assertOk();
    }

    public function test_retry_does_not_store_a_second_copy_and_conflicting_key_is_rejected(): void
    {
        Storage::fake('vehicle_photos');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        $input = $this->input();
        $url = "/api/v1/vehicles/{$client->id}/photos";
        $first = $this->postJson($url, $input)->assertCreated()->json('data.id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.id', $first);
        $this->postJson($url, [...$input, 'slot' => 'front'])->assertConflict()->assertJsonPath('code', 'UPLOAD_KEY_USED');
        $this->assertDatabaseCount('vehicle_photos', 1);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertCount(1, Storage::disk('vehicle_photos')->allFiles());
    }

    public function test_audit_failure_rolls_back_metadata_and_removes_new_blob(): void
    {
        Storage::fake('vehicle_photos');
        [$member, $client] = $this->setupClient();
        $this->login($member);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_fail_document_audit() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'Simulated audit failure'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_audit_failure BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION test_fail_document_audit();
            SQL);
        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input());
            $this->fail('Expected the audit storage failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Simulated audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('vehicle_photos', 0);
        $this->assertEmpty(Storage::disk('vehicle_photos')->allFiles());
    }

    public function test_guest_and_cross_site_uploads_are_blocked(): void
    {
        [$member, $client] = $this->setupClient();
        $this->getJson("/api/v1/vehicles/{$client->id}/photos/1/content")->assertUnauthorized();
        $this->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input())->assertUnauthorized();
        $this->login($member);
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeader('Origin', 'https://outside.example')->postJson("/api/v1/vehicles/{$client->id}/photos", $this->input())->assertStatus(419);
    }
}
