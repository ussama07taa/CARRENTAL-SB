<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VehicleDocumentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupVehicle(): array
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
    }

    private function login(CompanyMembership $member): void
    {
        $this->actingAs($member->user)->withSession(['company_id' => $member->company_id]);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['kind' => 'insurance', 'change_kind' => 'initial', 'previous_id' => null, 'request_key' => (string) Str::uuid(),
            'provider' => 'Assurance fictive', 'policy_number' => 'DEMO-001', 'effective_on' => '2026-01-01', 'expires_on' => '2026-12-31'], $extra);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
    }

    private function png(): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return UploadedFile::fake()->createWithContent('test.png', "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x80\x80\x80", 2), 2))).$chunk('IEND', ''));
    }

    public function test_private_pdf_upload_download_and_audit(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $id = $this->postJson("/api/v1/vehicles/$v->id/documents", $this->input(['file' => $this->pdf()]))->assertCreated()->assertJsonPath('data.has_file', true)->assertJsonMissingPath('data.path')->json('data.id');
        $d = Tenant::run($m->company_id, fn () => VehicleDocument::findOrFail($id));
        Storage::disk('vehicle_documents')->assertExists($d->path);
        $this->get("/api/v1/vehicles/$v->id/documents/$id/content")->assertOk()->assertDownload('insurance-v1.pdf')->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('vehicle_events', ['vehicle_id' => $v->id, 'actor_membership_id' => $m->id, 'action' => 'document_initial']);
        $this->getJson("/api/v1/vehicles/$v->id/documents")->assertOk()->assertJsonPath('data.insurance.latest.id', $id)->assertJsonPath('data.insurance.history.0.actor', $m->user->name);
    }

    public function test_metadata_without_file_is_distinct_from_expiry_and_registration_requires_file(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        $this->postJson($url, $this->input())->assertCreated()->assertJsonPath('data.has_file', false);
        $registration = ['kind' => 'registration', 'change_kind' => 'initial', 'previous_id' => null, 'request_key' => (string) Str::uuid()];
        $this->postJson($url, $registration)->assertUnprocessable()->assertJsonValidationErrors('file');
        Storage::fake('vehicle_documents');
        $this->postJson($url, [...$registration, 'file' => $this->png()])->assertCreated()->assertJsonPath('data.deadline.state', 'recorded');
    }

    public function test_renewal_preserves_old_file_and_optimistic_lock_and_retries(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        $first = $this->postJson($url, $this->input(['file' => $this->pdf()]))->assertCreated()->json('data.id');
        $input = $this->input(['change_kind' => 'renewal', 'previous_id' => $first, 'effective_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'file' => $this->pdf()]);
        $second = $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.id', $second);
        $this->postJson($url, [...$input, 'provider' => 'Different'])->assertConflict()->assertJsonPath('code', 'REQUEST_ALREADY_USED');
        $this->postJson($url, [...$input, 'request_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'DOCUMENT_CHANGED');
        $this->get("/api/v1/vehicles/$v->id/documents/$first/content")->assertOk();
        $this->assertDatabaseCount('vehicle_documents', 2);
        $this->assertCount(2, Storage::disk('vehicle_documents')->allFiles());
    }

    public function test_correction_requires_reason_and_can_retain_previous_attachment(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        $id = $this->postJson($url, $this->input(['file' => $this->pdf()]))->json('data.id');
        $input = $this->input(['change_kind' => 'correction', 'previous_id' => $id, 'retain_file' => true, 'expires_on' => '2026-12-01']);
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $new = $this->postJson($url, [...$input, 'reason' => 'Correction de date'])->assertCreated()->assertJsonPath('data.has_file', true)->json('data.id');
        $this->get("/api/v1/vehicles/$v->id/documents/$new/content")->assertOk();
        $this->assertCount(1, Storage::disk('vehicle_documents')->allFiles());
    }

    public function test_type_specific_dates_and_tax_payment_are_validated(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        $this->postJson($url, $this->input(['expires_on' => '2025-01-01']))->assertUnprocessable()->assertJsonValidationErrors('expires_on');
        $this->postJson($url, $this->input(['kind' => 'tax']))->assertUnprocessable()->assertJsonValidationErrors(['tax_year', 'paid_on', 'provider']);
        $tax = ['kind' => 'tax', 'change_kind' => 'initial', 'previous_id' => null, 'request_key' => (string) Str::uuid(), 'tax_year' => 2026, 'paid_on' => now()->toDateString()];
        $id = $this->postJson($url, $tax)->assertCreated()->assertJsonPath('data.expires_on', null)->json('data.id');
        $this->postJson($url, [...$tax, 'change_kind' => 'renewal', 'previous_id' => $id, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('tax_year');
        $this->postJson($url, ['kind' => 'inspection', 'change_kind' => 'initial', 'previous_id' => null, 'request_key' => (string) Str::uuid(), 'effective_on' => now()->addDay()->toDateString(), 'expires_on' => now()->addYear()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('effective_on');
    }

    public static function thresholds(): array
    {
        return [[31, 'valid'], [30, 'soon'], [8, 'soon'], [7, 'urgent'], [1, 'urgent'], [0, 'urgent'], [-1, 'overdue']];
    }

    #[DataProvider('thresholds')]
    public function test_exact_deadline_boundaries_in_morocco(int $offset, string $state): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 23:30:00', 'Africa/Casablanca'));
        try {
            [$m,$v] = $this->setupVehicle();
            $this->login($m);
            $deadline = CarbonImmutable::today('Africa/Casablanca')->addDays($offset)->toDateString();
            $this->postJson("/api/v1/vehicles/$v->id/documents", $this->input(['expires_on' => $deadline]))->assertCreated()->assertJsonPath('data.deadline.state', $state)->assertJsonPath('data.deadline.days', $offset);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_future_policy_does_not_hide_current_policy_deadline(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        $end = now('Africa/Casablanca')->addDays(5)->toDateString();
        $first = $this->postJson($url, $this->input(['effective_on' => now()->subYear()->toDateString(), 'expires_on' => $end]))->assertCreated()->json('data.id');
        $this->postJson($url, $this->input(['change_kind' => 'renewal', 'previous_id' => $first, 'effective_on' => now()->addDays(6)->toDateString(), 'expires_on' => now()->addYear()->toDateString()]))->assertCreated()->assertJsonPath('data.deadline.state', 'scheduled');
        $this->getJson($url)->assertOk()->assertJsonPath('data.insurance.current.id', $first)->assertJsonPath('data.insurance.current.deadline.state', 'urgent');
    }

    public function test_future_renewal_keeps_expired_previous_policy_visible(): void
    {
        [$m, $v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        $first = $this->postJson($url, $this->input(['effective_on' => now()->subYear()->toDateString(), 'expires_on' => now()->subDay()->toDateString()]))->assertCreated()->json('data.id');
        $this->postJson($url, $this->input(['change_kind' => 'renewal', 'previous_id' => $first, 'effective_on' => now()->addDays(6)->toDateString(), 'expires_on' => now()->addYear()->toDateString()]))->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.insurance.latest.deadline.state', 'scheduled')->assertJsonPath('data.insurance.current.id', $first)->assertJsonPath('data.insurance.current.deadline.state', 'overdue');
    }

    public function test_guests_other_agencies_and_wrong_parent_are_denied(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $url = "/api/v1/vehicles/$v->id/documents";
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, $this->input())->assertUnauthorized();
        $this->login($m);
        $id = $this->postJson($url, $this->input(['file' => $this->pdf()]))->json('data.id');
        [$other,$car] = $this->setupVehicle();
        $this->login($other);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, $this->input())->assertNotFound();
        $this->getJson("$url/$id/content")->assertNotFound();
        $this->getJson("/api/v1/vehicles/$car->id/documents/$id/content")->assertNotFound();
        $this->withSession(['company_id' => $m->company_id])->getJson($url)->assertForbidden();
    }

    public function test_bad_files_and_tenant_injection_are_rejected(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/documents";
        foreach ([UploadedFile::fake()->createWithContent('x.pdf', 'not a pdf'), UploadedFile::fake()->createWithContent('x.svg', '<svg/>'), $this->pdf()->size(5121)] as $file) {
            $this->postJson($url, $this->input(['file' => $file]))->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->postJson($url, $this->input(['company_id' => 999]))->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_documents', 0);
    }

    public function test_history_remains_accessible_after_twenty_versions(): void
    {
        [$member, $vehicle] = $this->setupVehicle();
        Tenant::run($member->company_id, function () use ($member, $vehicle): void {
            for ($version = 1; $version <= 23; $version++) {
                VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'actor_membership_id' => $member->id, 'version' => $version]);
            }
        });
        $this->login($member);
        $this->getJson("/api/v1/vehicles/$vehicle->id/documents")->assertOk()->assertJsonPath('data.insurance.total', 23)->assertJsonCount(20, 'data.insurance.history');
        $this->getJson("/api/v1/vehicles/$vehicle->id/documents/history?kind=insurance&before_version=4")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.version', 3)->assertJsonPath('data.2.version', 1);
        [$other] = $this->setupVehicle();
        $this->login($other);
        $this->getJson("/api/v1/vehicles/$vehicle->id/documents/history?kind=insurance&before_version=4")->assertNotFound();
    }

    public function test_database_history_is_immutable(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $this->postJson("/api/v1/vehicles/$v->id/documents", $this->input())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('vehicle_documents')->update(['provider' => 'Tampered']);
    }

    public function test_audit_failure_rolls_back_blob_and_document(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        DB::unprepared("CREATE FUNCTION fail_doc_event() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit fail'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER fail_doc_event BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION fail_doc_event();");
        $this->postJson("/api/v1/vehicles/$v->id/documents", $this->input(['file' => $this->pdf()]))->assertServerError();
        $this->assertDatabaseCount('vehicle_documents', 0);
        $this->assertEmpty(Storage::disk('vehicle_documents')->allFiles());
    }
}
