<?php

namespace Tests\Feature;

use App\Jobs\RenderRentalContract;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Reservations\RentalPayment;
use App\Support\Reservations\TabletContractPdf;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TabletContractSignatureTest extends TestCase
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

    private function signature(): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = '';
        for ($y = 0; $y < 180; $y++) {
            $rows .= "\0";
            for ($x = 0; $x < 600; $x++) {
                $rows .= abs($y - 60 - ($x % 100)) < 4 ? "\x17\x3b\x53\xff" : "\xff\xff\xff\0";
            }
        }

        return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 600, 180, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($rows)).$chunk('IEND', ''));
    }

    private function tablet(): array
    {
        config(['rental_contracts.tablet_signing_enabled' => true]);
        [$company, $actor, $client, $car, $id] = $this->readyFile();
        $contract = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $this->render($company->id, $contract);
        $original = DB::table('rental_contract_files')->where('kind', 'original')->first();
        $input = ['client_signature' => $this->signature(), 'agency_signature' => $this->signature(), 'original_id' => $original->id, 'original_sha256' => $original->sha256, 'expected_signed_id' => null, 'contract_read' => true];
        $renderer = \Mockery::mock(TabletContractPdf::class);
        $renderer->shouldReceive('render')->andReturn("%PDF-1.4\nsigned test\n%%EOF");
        $this->app->instance(TabletContractPdf::class, $renderer);

        return [$id, $contract, $input, $original, $company, $actor, $client];
    }

    private function previewTablet(int $id, int $contract, array $input): array
    {
        return $this->postJson("/api/v1/reservations/$id/departure/contract/$contract/tablet/preview", $input)->assertOk()->json();
    }

    private function confirmation(array $preview): array
    {
        return ['preview_token' => $preview['preview_token'], 'preview_sha256' => $preview['sha256'], 'preview_checked' => true, 'both_consented' => true, 'reason' => 'Signature fictive après revue du PDF', 'request_key' => (string) Str::uuid()];
    }

    public function test_preview_is_not_a_saved_copy_and_confirmation_preserves_exact_preview_and_original(): void
    {
        [$id, $contract, $input, $original] = $this->tablet();
        $bytes = Storage::disk('rental_contracts')->get($original->path);
        $preview = $this->previewTablet($id, $contract, $input);
        $url = "/api/v1/reservations/$id/departure/contract/$contract/tablet";
        $pdf = $this->get("$url/preview/{$preview['preview_token']}")->assertOk()->getContent();
        $this->assertSame($preview['sha256'], hash('sha256', $pdf));
        $this->assertDatabaseCount('rental_contract_files', 1);
        $confirmation = $this->confirmation($preview);
        $this->postJson("$url/confirm", [...$confirmation, 'both_consented' => false])->assertUnprocessable();
        $this->postJson("$url/confirm", [...$confirmation, 'preview_sha256' => str_repeat('0', 64)])->assertConflict();
        $this->postJson("$url/confirm", $confirmation)->assertCreated()->assertJsonPath('data.signed_verified', false);
        $this->postJson("$url/confirm", $confirmation)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("$url/confirm", [...$confirmation, 'reason' => 'Different data'])->assertConflict();
        $this->assertDatabaseCount('rental_contract_files', 2);
        $signed = DB::table('rental_contract_files')->where('kind', 'signed')->first();
        $this->assertSame($pdf, Storage::disk('rental_contracts')->get($signed->path));
        $this->assertSame($bytes, Storage::disk('rental_contracts')->get($original->path));
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'contract_tablet_copy_saved')->count());
        $this->postJson("/api/v1/reservations/$id/departure/contract/$contract/verify", ['file_id' => $signed->id, 'signatures_checked' => true, 'reason' => 'Copie et signatures revues', 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.signed_verified', true)->assertJsonPath('data.valid_for_pickup', false);
    }

    public function test_feature_is_test_only_and_preview_is_actor_and_tenant_private(): void
    {
        [$id,$contract,$input,,$company,$actor] = $this->tablet();
        $url = "/api/v1/reservations/$id/departure/contract/$contract/tablet";
        config(['rental_contracts.tablet_signing_enabled' => false]);
        $this->postJson("$url/preview", $input)->assertForbidden();
        config(['rental_contracts.tablet_signing_enabled' => true]);
        $this->withoutMiddleware(PreventRequestForgery::class);
        foreach (['production', 'demo', 'local'] as $environment) {
            $this->app->instance('env', $environment);
            $this->postJson("$url/preview", $input)->assertForbidden();
        }
        $this->app->instance('env', 'testing');
        $preview = $this->previewTablet($id, $contract, $input);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->postJson("$url/preview", $input)->assertForbidden();
        $this->get("$url/preview/{$preview['preview_token']}")->assertForbidden();
        $other = Company::factory()->create();
        $otherUser = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherUser->user)->withSession(['company_id' => $other->id]);
        $this->postJson("$url/preview", $input)->assertNotFound();
        $this->postJson("$url/confirm", $this->confirmation($preview))->assertNotFound();
        $this->get("$url/preview/{$preview['preview_token']}")->assertNotFound();
    }

    public function test_changed_dossier_and_new_signed_copy_block_old_preview(): void
    {
        [$id,$contract,$input] = $this->tablet();
        $preview = $this->previewTablet($id, $contract, $input);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$contract/signed", $this->uploadInput())->assertCreated();
        $url = "/api/v1/reservations/$id/departure/contract/$contract/tablet";
        $this->postJson("$url/confirm", $this->confirmation($preview))->assertConflict();
        $this->postJson("$url/preview", $input)->assertConflict();
        $new = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $this->postJson("$url/confirm", $this->confirmation($preview))->assertConflict();
        $this->assertDatabaseCount('rental_contract_files', 2);
    }

    public function test_preview_expiry_and_invalid_signatures_do_not_create_files(): void
    {
        [$id,$contract,$input] = $this->tablet();
        $url = "/api/v1/reservations/$id/departure/contract/$contract/tablet";
        foreach ([['client_signature' => ''], ['agency_signature' => 'data:image/svg+xml;base64,dGVzdA=='], ['contract_read' => false], ['original_sha256' => str_repeat('0', 64)]] as $change) {
            $response = $this->postJson("$url/preview", [...$input, ...$change]);
            $this->assertContains($response->status(), [409, 422]);
        }
        $preview = $this->previewTablet($id, $contract, $input);
        $this->travel(16)->minutes();
        $this->get("$url/preview/{$preview['preview_token']}")->assertGone();
        $this->postJson("$url/confirm", $this->confirmation($preview))->assertGone();
        $this->assertDatabaseCount('rental_contract_files', 1);
    }

    public function test_dossier_edit_invalidates_preview_even_with_same_contract_id(): void
    {
        [$id,$contract,$input,,,$actor,$client] = $this->tablet();
        $preview = $this->previewTablet($id, $contract, $input);
        DB::table('customers')->where('id', $client->id)->update(['identity_number' => 'UPDATED-TEST']);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$contract/tablet/confirm", $this->confirmation($preview))->assertConflict();
        $this->assertDatabaseCount('rental_contract_files', 1);
    }

    public function test_real_renderer_preserves_two_a4_pages_and_original_bytes(): void
    {
        $original = file_get_contents(base_path('../../output/pdf/bensbehicarrental-contrat-design-reference-v3.pdf'));
        $hash = hash('sha256', $original);
        $pdf = app(TabletContractPdf::class)->render($original, ['client' => $this->signature(), 'agency' => $this->signature(), 'client_name' => 'Client TEST', 'agency_name' => 'Agence TEST'], 'CTR-TEST-TABLET', $hash, '2026-10-20 12:00:00');
        $dir = base_path('../../.local/tablet-signature-qa');
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/signed-test.pdf', $pdf);
        $process = new Process(['pdfinfo', $dir.'/signed-test.pdf']);
        $process->mustRun();
        $this->assertMatchesRegularExpression('/Pages:\s+2\s*$/m', $process->getOutput());
        $this->assertStringContainsString('(A4)', $process->getOutput());
        $this->assertSame($hash, hash('sha256', $original));
        $this->assertLessThan(5 * 1024 * 1024, strlen($pdf));
    }

    public function test_new_layout_freezes_available_information_and_signs_inside_reserved_boxes(): void
    {
        [$company, $actor, $customer, $car, $id] = $this->readyFile();
        DB::table('vehicles')->where('id', $car->id)->update(['fuel' => 'diesel']);
        DB::table('customers')->where('id', $customer->id)->update(['address' => 'Adresse fictive de contrôle, Tanger']);
        $contract = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $snapshot = json_decode(DB::table('rental_contract_versions')->where('id', $contract)->value('snapshot'), true);
        $this->assertSame('diesel', $snapshot['presentation']['fuel']);
        $this->assertSame(['especes'], $snapshot['presentation']['verified_payment_methods']);
        $this->assertNull($snapshot['presentation']['agreed_daily_centimes']);
        $this->assertNull($snapshot['presentation']['first_daily_centimes']);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertJsonPath('data.stale', false)->assertJsonPath('data.versions.0.layout', 4);
        $dir = base_path('../../.local/contract-l4-qa');
        File::ensureDirectoryExists($dir);
        foreach (['fr', 'ar'] as $language) {
            $snapshot['language'] = $language;
            $original = app(ContractPdf::class)->render($snapshot, 'CTR-TEST-L4');
            file_put_contents($dir.'/original-'.$language.'.pdf', $original);
            $pdf = app(TabletContractPdf::class)->render($original, ['client' => $this->signature(), 'agency' => $this->signature(), 'client_name' => 'Client TEST', 'agency_name' => 'Agence TEST'], 'CTR-TEST-L4', hash('sha256', $original), '2026-10-20 12:00:00');
            file_put_contents($dir.'/signed-'.$language.'.pdf', $pdf);
            $info = new Process(['pdfinfo', $dir.'/signed-'.$language.'.pdf']);
            $info->mustRun();
            $this->assertMatchesRegularExpression('/Pages:\s+2\s*$/m', $info->getOutput());
            $this->assertStringContainsString('(A4)', $info->getOutput());
            $this->assertStringContainsString('signatures de test L4', $info->getOutput());
            $text = new Process(['pdftotext', $dir.'/signed-'.$language.'.pdf', '-']);
            $text->mustRun();
            $this->assertStringContainsString('TEST NON CERTIFIÉ', $text->getOutput());
            $this->assertLessThan(5 * 1024 * 1024, strlen($pdf));
            $text = new Process(['pdftotext', $dir.'/original-'.$language.'.pdf', '-']);
            $text->mustRun();
            $this->assertStringContainsString('MOSWADA · L4', $text->getOutput());
            if ($language === 'fr') {
                $this->assertStringContainsString('Espèces', $text->getOutput());
                $this->assertStringContainsString('Diesel', $text->getOutput());
                $this->assertStringContainsString('Adresse fictive de contrôle', $text->getOutput());
            }
        }
    }

    public function test_new_display_snapshot_does_not_invalidate_or_rewrite_previous_versions(): void
    {
        [$company, , , $car, $id] = $this->readyFile();
        DB::table('vehicles')->where('id', $car->id)->update(['fuel' => 'diesel']);
        $first = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $this->render($company->id, $first);
        $version = DB::table('rental_contract_versions')->where('id', $first)->first();
        $file = DB::table('rental_contract_files')->where('contract_id', $first)->first();
        $bytes = Storage::disk('rental_contracts')->get($file->path);
        DB::table('vehicles')->where('id', $car->id)->update(['fuel' => 'petrol']);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertJsonPath('data.stale', false);
        $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 1000, 'method' => 'virement', 'received_on' => '2026-10-19', 'reference' => 'VIR-TEST-PENDING', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $second = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $new = json_decode(DB::table('rental_contract_versions')->where('id', $second)->value('snapshot'), true);
        $this->assertSame('petrol', $new['presentation']['fuel']);
        $this->assertSame(['especes'], $new['presentation']['verified_payment_methods']);
        $this->assertSame($version->snapshot, DB::table('rental_contract_versions')->where('id', $first)->value('snapshot'));
        $this->assertSame($version->fingerprint, DB::table('rental_contract_versions')->where('id', $first)->value('fingerprint'));
        $this->assertSame($bytes, Storage::disk('rental_contracts')->get($file->path));
    }

    public function test_first_and_last_day_are_reused_only_from_the_accepted_calculated_snapshot(): void
    {
        [$company, , , , $id] = $this->readyFile();
        $financial = \Mockery::mock(RentalPayment::class);
        $financial->shouldReceive('summary')->twice()->andReturn(
            ['payments' => [], 'pricing_snapshot' => ['agreement_mode' => 'calculated', 'lines' => [['daily_centimes' => 80000], ['daily_centimes' => 90000], ['daily_centimes' => 100000]]]],
            ['payments' => [], 'pricing_snapshot' => ['agreement_mode' => 'negotiated', 'lines' => [['daily_centimes' => 80000], ['daily_centimes' => 100000]]]]
        );
        $this->app->instance(RentalPayment::class, $financial);
        Tenant::run($company->id, function () use ($id): void {
            $r = Reservation::findOrFail($id);
            $data = app(RentalContract::class)->presentation($r);
            $this->assertSame(80000, $data['first_daily_centimes']);
            $this->assertSame(100000, $data['last_daily_centimes']);
            $this->assertNull($data['agreed_daily_centimes']);
            $negotiated = app(RentalContract::class)->presentation($r);
            $this->assertNull($negotiated['first_daily_centimes']);
            $this->assertNull($negotiated['last_daily_centimes']);
        });
    }

    public function test_content_cannot_cover_the_signature_rectangles(): void
    {
        [, , , , $id] = $this->readyFile();
        $contract = $this->issue($id, $this->issueInput($id))->assertCreated()->json('data.latest_id');
        $snapshot = json_decode(DB::table('rental_contract_versions')->where('id', $contract)->value('snapshot'), true);
        $snapshot['inspection']['notes'] = str_repeat('Observation fictive longue à conserver dans une annexe. ', 250);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('annexe lisible');
        app(ContractPdf::class)->render($snapshot, 'CTR-TEST-OVERFLOW');
    }
}
