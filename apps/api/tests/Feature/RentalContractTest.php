<?php

namespace Tests\Feature;

use App\Jobs\RenderRentalContract;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RentalContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $confirmed = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('inspection_photos');
        Storage::fake('rental_contracts');
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00Z'));
        $company = Company::factory()->create();
        [$actor, $client, $car] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(['identity_type' => 'cin', 'identity_number' => 'TEST123', 'licence_number' => 'LIC123', 'licence_expires_on' => '2030-01-01']), Vehicle::factory()->create()]);
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        if ($confirmed) {
            $quote = $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 270000, 'reason' => 'Prix convenu fictif', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
            $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 270000, 'method' => 'especes', 'received_on' => '2026-10-19', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
            $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        }

        return [$company, $actor, $client, $car, $id];
    }

    private function payload(int $id, array $changes = []): array
    {
        $data = $this->getJson("/api/v1/reservations/$id/departure/inspection")->assertOk()->json('data');

        return [...['expected_inspection_id' => $data['latest']['id'] ?? null, 'vehicle_version' => $data['vehicle']['version'], 'inspected_on' => '2026-10-19', 'kilometers' => max(20000, $data['vehicle']['current_odometer'] ?? 0), 'fuel_eighths' => 6, 'condition' => 'bon', 'notes' => null, 'reason' => 'Contrôle réel fictif', 'checked' => true, 'photos' => [UploadedFile::fake()->createWithContent('car.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII='))], 'captions' => ['Avant du véhicule'], 'request_key' => (string) Str::uuid()], ...$changes];
    }

    private function inspect(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/departure/inspection", $input);
    }

    private function readyFile(): array
    {
        $fixture = $this->fixture();
        [,,$client,,$id] = $fixture;
        $data = $this->getJson("/api/v1/reservations/$id/departure")->assertOk()->json('data');
        foreach ([['action' => 'documents_checked', 'fingerprint' => $data['documents']['fingerprint'], 'identity_original' => true, 'licence_original' => true], ['action' => 'caution_waived', 'expected_terms_id' => null]] as $action) {
            $this->postJson("/api/v1/reservations/$id/departure", [...$action, 'reason' => 'Décision de test fictive', 'request_key' => (string) Str::uuid()])->assertCreated();
        }
        $this->inspect($id, $this->payload($id))->assertCreated();

        return $fixture;
    }

    private function issueInput(int $id): array
    {
        $data = $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->json('data');

        return ['expected_latest_id' => $data['latest_id'], 'fingerprint' => $data['fingerprint'], 'reviewed' => true, 'reason' => 'Contrat de test fictif', 'request_key' => (string) Str::uuid()];
    }

    private function issue(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/departure/contract", $input);
    }

    private function render(int $company, int $contract): void
    {
        $pdf = \Mockery::mock(ContractPdf::class);
        $pdf->shouldReceive('render')->once()->andReturn("%PDF-1.4\noriginal bytes\n%%EOF");
        (new RenderRentalContract($company, $contract))->handle($pdf, app(RentalContract::class));
    }

    private function uploadInput(?int $previous = null): array
    {
        return ['file' => UploadedFile::fake()->createWithContent('signed.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII=')), 'reason' => 'Copie signée fictive', 'expected_signed_id' => $previous, 'same_version' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_issue_requires_departure_checks_and_current_review_and_is_idempotent(): void
    {
        [,,,,$missing] = $this->fixture();
        $this->issue($missing, $this->issueInput($missing))->assertUnprocessable();
        [$company,,,,$id] = $this->readyFile();
        $input = $this->issueInput($id);
        $this->issue($id, [...$input, 'reviewed' => false])->assertUnprocessable();
        $contract = $this->issue($id, $input)->assertCreated()->json('data.latest_id');
        $this->issue($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->issue($id, [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('rental_contract_versions', 1);
        Queue::assertPushed(RenderRentalContract::class, 1);
        $this->render($company->id, $contract);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertJsonPath('data.versions.0.status', 'ready');
    }

    public function test_additional_driver_requires_verification_and_a_new_contract_without_rewriting_original(): void
    {
        [$company, , , , $id] = $this->readyFile();
        $first = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $this->render($company->id, $first);
        $original = DB::table('rental_contract_versions')->where('id', $first)->first();
        $file = DB::table('rental_contract_files')->where('contract_id', $first)->where('kind', 'original')->first();
        $driver = Tenant::run($company->id, fn () => Customer::factory()->create(['first_name' => 'ConducteurTest', 'identity_type' => 'cin', 'identity_number' => 'ADD123', 'licence_number' => 'LICADD', 'licence_expires_on' => '2030-01-01']));
        $assigned = $this->postJson("/api/v1/reservations/$id/drivers", ['action' => 'assigned', 'customer_id' => $driver->id, 'expected_revision' => null, 'reason' => 'Ajout convenu fictif', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertJsonPath('data.stale', true);
        $this->issue($id, $this->issueInput($id))->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/drivers", ['action' => 'verified', 'expected_revision' => $assigned['revision'], 'fingerprint' => $assigned['fingerprint'], 'identity_original' => true, 'licence_original' => true, 'reason' => 'Originaux fictifs contrôlés', 'request_key' => (string) Str::uuid()])->assertCreated();
        $second = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $new = DB::table('rental_contract_versions')->where('id', $second)->first();
        $this->assertEquals($first, $new->previous_id);
        $this->assertSame($driver->id, json_decode($new->snapshot, true)['additional_driver']['id']);
        $this->assertEquals($original, DB::table('rental_contract_versions')->where('id', $first)->first());
        $this->assertEquals($file, DB::table('rental_contract_files')->where('id', $file->id)->first());
        $snapshot = json_decode($new->snapshot, true);
        foreach (['fr', 'ar'] as $language) {
            $html = (new ContractPdf)->html([...$snapshot, 'language' => $language], 'TEST-V2');
            $this->assertStringContainsString('ConducteurTest', $html);
            $this->assertStringContainsString('LICADD', $html);
        }
    }

    public function test_original_bytes_remain_identical_after_changes_and_new_versions(): void
    {
        [$company,,$client,,$id] = $this->readyFile();
        $v = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $this->render($company->id, $v);
        $file = DB::table('rental_contract_files')->where('kind', 'original')->first();
        $url = "/api/v1/reservations/$id/departure/contract/files/$file->id/content";
        $original = $this->get($url)->assertOk()->streamedContent();
        $this->assertSame(hash('sha256', $original), $file->sha256);
        DB::table('customers')->where('id', $client->id)->update(['first_name' => 'Changed']);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertJsonPath('data.stale', true);
        $this->assertSame($original, $this->get($url)->assertOk()->streamedContent());
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/signed", $this->uploadInput())->assertConflict();
        $fingerprint = $this->getJson("/api/v1/reservations/$id/departure")->json('data.documents.fingerprint');
        $this->postJson("/api/v1/reservations/$id/departure", ['action' => 'documents_checked', 'fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true, 'reason' => 'Vérification après modification', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->issue($id, $this->issueInput($id))->assertCreated()->assertJsonPath('data.versions.0.previous_id', $v)->assertJsonCount(2, 'data.versions');
        $this->assertSame($original, $this->get($url)->assertOk()->streamedContent());
    }

    public function test_signed_copy_needs_explicit_verification_and_replacement_invalidates_it(): void
    {
        [$company,,,,$id] = $this->readyFile();
        $v = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $input = $this->uploadInput();
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/signed", $input)->assertUnprocessable();
        $this->render($company->id, $v);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/signed", $input)->assertCreated()->assertJsonPath('data.signed_verified', false);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/signed", $input)->assertOk()->assertJsonPath('replayed', true);
        $signed = DB::table('rental_contract_files')->where('kind', 'signed')->first();
        $verify = ['file_id' => $signed->id, 'signatures_checked' => true, 'reason' => 'Toutes les pages et signatures vérifiées', 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/verify", [...$verify, 'signatures_checked' => false])->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/verify", $verify)->assertCreated()->assertJsonPath('data.signed_verified', true)->assertJsonPath('data.valid_for_pickup', false);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/verify", $verify)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson("/api/v1/reservations/$id/departure")->assertJsonPath('data.can_pick_up', false)->assertJsonPath('data.contract_signed', false);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/signed", $this->uploadInput())->assertConflict();
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/signed", $this->uploadInput($signed->id))->assertCreated()->assertJsonPath('data.signed_verified', false);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/verify", [...$verify, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('rental_contract_files', 3);
    }

    public function test_render_failure_retry_and_duplicate_worker_preserve_one_original(): void
    {
        [$company,,,,$id] = $this->readyFile();
        $v = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $job = new RenderRentalContract($company->id, $v);
        $pdf = \Mockery::mock(ContractPdf::class);
        $pdf->shouldReceive('render')->once()->andThrow(new \RuntimeException('Renderer unavailable'));
        try {
            $job->handle($pdf, app(RentalContract::class));
            $this->fail('Expected rendering failure');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }
        $this->assertDatabaseCount('rental_contract_files', 0);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertJsonPath('data.versions.0.status', 'failed');
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/retry")->assertAccepted();
        $this->render($company->id, $v);
        $pdf = \Mockery::mock(ContractPdf::class);
        $pdf->shouldNotReceive('render');
        $job->handle($pdf, app(RentalContract::class));
        $this->assertDatabaseCount('rental_contract_files', 1);
        $this->assertDatabaseCount('rental_contract_versions', 1);
        $this->assertCount(1, Storage::disk('rental_contracts')->allFiles());
    }

    public function test_corrupted_original_is_reported_without_regenerating_and_other_tenants_are_denied(): void
    {
        [$company,,,,$id] = $this->readyFile();
        $input = $this->issueInput($id);
        $v = $this->issue($id, $input)->assertCreated()->json('data.latest_id');
        $this->render($company->id, $v);
        $file = DB::table('rental_contract_files')->first();
        Storage::disk('rental_contracts')->put($file->path, 'changed bytes');
        $this->getJson("/api/v1/reservations/$id/departure/contract/files/$file->id/content")->assertConflict();
        $this->fixture();
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertNotFound();
        $this->issue($id, $input)->assertNotFound();
        $this->getJson("/api/v1/reservations/$id/departure/contract/files/$file->id/content")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v/retry")->assertNotFound();
    }

    public function test_snapshot_is_append_only(): void
    {
        [,,,,$id] = $this->readyFile();
        $this->issue($id, $this->issueInput($id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('rental_contract_versions')->update(['reason' => 'Overwrite']);
    }
}
