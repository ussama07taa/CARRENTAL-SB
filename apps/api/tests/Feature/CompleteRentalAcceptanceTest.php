<?php

namespace Tests\Feature;

use App\Jobs\RenderRentalContract;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CompleteRentalAcceptanceTest extends TestCase
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

            $quote = $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 270000, 'vat_mode' => 'included', 'vat_rate' => '20', 'reason' => 'Prix convenu fictif', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');

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

    private function pickupInput(int $id): array
    {

        $d = $this->getJson("/api/v1/reservations/$id/pickup")->assertOk()->json('data');

        return ['late_agreement_confirmed' => true, 'late_agreement_reason' => 'Client tardif : accord initial maintenu', 'picked_up_local' => $d['server_now_local'], 'review_fingerprint' => $d['review_fingerprint'], 'vehicle_present' => true, 'driver_reviewed' => true, 'keys_handed_over' => true, 'reason' => 'Remise effective fictive', 'request_key' => (string) Str::uuid()];

    }

    private function pickup(int $id, array $input): TestResponse
    {

        return $this->postJson("/api/v1/reservations/$id/pickup", $input);

    }

    private function returnInput(int $id): array
    {

        return ['returned_local' => now('Africa/Casablanca')->format('Y-m-d\TH:i'), 'vehicle_received' => true, 'reason' => 'Retour physique FICTIF TEST', 'request_key' => (string) Str::uuid()];

    }

    private function receive(int $id, array $input): TestResponse
    {

        return $this->postJson("/api/v1/reservations/$id/return", $input);

    }

    private function control(int $id, array $changes = []): array
    {

        $data = $this->getJson("/api/v1/reservations/$id/return")->assertOk()->json('data');

        return [...['expected_inspection_id' => $data['inspection']['id'] ?? null, 'vehicle_version' => $data['vehicle']['version'], 'kilometers' => 20200, 'fuel_eighths' => 4, 'condition' => 'bon', 'notes' => null, 'reason' => 'Contrôle retour FICTIF TEST', 'signature_state' => 'pending', 'signature_reason' => 'Les parties signeront après revue', 'checked' => true, 'photos' => [UploadedFile::fake()->createWithContent('return.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII='))], 'captions' => ['Photo retour TEST'], 'request_key' => (string) Str::uuid()], ...$changes];

    }

    private function inspectReturn(int $id, array $input): TestResponse
    {

        return $this->postJson("/api/v1/reservations/$id/return/inspection", $input);

    }

    private function settlementInput(int $id, string $kind, string $scope, array $changes = []): array
    {

        return [...['kind' => $kind, 'scope' => $scope, 'amount_centimes' => 1000, 'expected_revision' => $this->getJson("/api/v1/reservations/$id/settlement")->json('data.revision'), 'occurred_on' => now()->toDateString(), 'reason' => 'Règlement FICTIF TEST documenté', 'checked' => true, 'request_key' => (string) Str::uuid()], ...$changes];

    }

    public function test_complete_journey_with_real_pdfs_garage_readiness_and_single_maintenance_cost(): void
    {
        $this->assertSame('bensebih_test', DB::connection()->getDatabaseName());
        if (! config('rental_contracts.chrome')) {
            $this->markTestSkipped('Chrome required for real document acceptance');
        }
        config(['rental_contracts.real_documents_enabled' => true, 'rental_contracts.demo_only' => false, 'rental_contracts.model_version' => 'rental-1']);
        [$company,$actor,$client,$car,$id] = $this->fixture();
        Storage::fake('vehicle_documents');
        Storage::fake('rental_invoices');
        Storage::fake('rental_returns');
        DB::table('agency_profiles')->insert(['company_id' => $company->id, 'details' => json_encode(['name' => 'Agence fictive recette', 'address' => 'Adresse fictive Tanger', 'ice' => '123456789012345', 'rc' => 'TEST123', 'if' => 'TEST456']), 'created_at' => now(), 'updated_at' => now()]);
        Tenant::run($company->id, function () use ($actor, $car) {
            foreach (['registration', 'insurance', 'inspection', 'circulation'] as $kind) {
                Storage::disk('vehicle_documents')->put($kind.'.png', 'fixture');
                VehicleDocument::create(['vehicle_id' => $car->id, 'actor_membership_id' => $actor->id, 'kind' => $kind, 'version' => 1, 'change_kind' => 'initial', 'effective_on' => $kind === 'registration' ? null : '2026-01-01', 'expires_on' => $kind === 'registration' ? null : '2030-01-01', 'disk' => 'vehicle_documents', 'path' => $kind.'.png', 'mime' => 'image/png', 'size' => 7, 'sha256' => hash('sha256', 'fixture'), 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'fixture')]);
            }
        });
        $b = "/api/v1/reservations/$id";
        $d = $this->getJson($b.'/departure')->assertOk()->json('data');
        $this->postJson($b.'/departure', ['action' => 'documents_checked', 'fingerprint' => $d['documents']['fingerprint'], 'identity_original' => true, 'licence_original' => true, 'reason' => 'Vérification fictive', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($b.'/departure', ['action' => 'caution_set', 'expected_terms_id' => null, 'amount_centimes' => 200000, 'reason' => 'Garantie fictive', 'request_key' => (string) Str::uuid()])->assertCreated();
        $deposit = ['action' => 'caution_received', 'amount_centimes' => 200000, 'method' => 'especes', 'received_on' => '2026-10-19', 'receipt_attested' => true, 'reason' => 'Reçu fictif', 'request_key' => (string) Str::uuid()];
        $this->postJson($b.'/departure', $deposit)->assertCreated();
        $this->postJson($b.'/departure', $deposit)->assertOk()->assertJsonPath('replayed', true);
        $receipt = $this->getJson($b.'/departure')->json('data.caution.receipts.0.id');
        $this->inspect($id, $this->payload($id))->assertCreated();
        $c = $this->getJson($b.'/departure/contract')->assertOk()->json('data');
        $v = $this->postJson($b.'/departure/contract', ['expected_latest_id' => null, 'fingerprint' => $c['fingerprint'], 'reviewed' => true, 'reason' => 'Recette isolée', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.latest_id');
        (new RenderRentalContract($company->id, $v))->handle(app(ContractPdf::class), app(RentalContract::class));
        $file = DB::table('rental_contract_files')->where('contract_id', $v)->where('kind', 'original')->first();
        $original = $this->get($b.'/departure/contract/files/'.$file->id.'/content')->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-', $original);
        $signed = $this->postJson($b.'/departure/contract/'.$v.'/signed', ['file' => UploadedFile::fake()->createWithContent('signed-test.png', file_get_contents(base_path('tests/Fixtures/signature.png'))), 'reason' => 'Copie fictive uniquement', 'expected_signed_id' => null, 'same_version' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $sf = DB::table('rental_contract_files')->where('kind', 'signed')->first();
        $this->postJson($b.'/departure/contract/'.$v.'/verify', ['file_id' => $sf->id, 'signatures_checked' => true, 'reason' => 'Recette isolée signatures', 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.valid_for_pickup', true);
        $preview = $this->getJson($b.'/invoice-preview')->assertOk();
        $invoice = ['review_hash' => $preview->json('review_hash'), 'reviewed' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson($b.'/invoice', $invoice)->assertCreated()->assertJsonPath('invoice.number', 'FAC-2026-0001');
        $this->postJson($b.'/invoice', $invoice)->assertOk()->assertJsonPath('replayed', true);
        $invoiceBytes = $this->get($b.'/invoice/content')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $invoiceBytes);
        $this->travelTo(CarbonImmutable::parse('2026-10-22T10:15:00Z'));
        $mb = "/api/v1/vehicles/$car->id/maintenance";
        $maintenance = ['kind' => 'oil', 'title' => 'Vidange recette', 'garage' => 'Garage fictif', 'performed_on' => '2026-10-22', 'cost_centimes' => 65000, 'paid_centimes' => 20000, 'paid_on' => '2026-10-22', 'method' => 'cash', 'in_garage' => true, 'request_key' => (string) Str::uuid()];
        $entry = $this->postJson($mb, $maintenance)->assertCreated()->json('id');
        $this->pickup($id, $this->pickupInput($id))->assertUnprocessable()->assertJsonPath('code', 'PICKUP_BLOCKED');
        $this->postJson("$mb/$entry/return", ['returned_on' => '2026-10-22', 'note' => 'Retour sans validation', 'ready' => false, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->pickup($id, $this->pickupInput($id))->assertUnprocessable()->assertJsonPath('code', 'PICKUP_BLOCKED');
        $md = $this->getJson("$mb/$entry/file")->assertOk()->json('data');
        $this->postJson("$mb/$entry/readiness", ['followup_return_id' => null, 'revision' => $md['revision'], 'checked_on' => '2026-10-22', 'note' => 'Contrôle terminé', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $pick = $this->pickupInput($id);
        $this->pickup($id, $pick)->assertCreated();
        $this->pickup($id, $pick)->assertOk()->assertJsonPath('replayed', true);
        $this->travel(2)->hours();
        $ret = $this->returnInput($id);
        $this->receive($id, $ret)->assertCreated();
        $this->receive($id, $ret)->assertOk()->assertJsonPath('replayed', true);
        $sig = 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/signature.png')));
        $inspection = $this->inspectReturn($id, $this->control($id, ['fuel_eighths' => 6, 'signature_state' => 'signed', 'signature_reason' => null, 'client_signature' => $sig, 'agency_signature' => $sig]))->assertCreated()->json('data.inspection.id');
        $this->postJson($b.'/return/readiness', ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Retour propre contrôlé', 'request_key' => (string) Str::uuid()])->assertCreated();
        $refund = $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 200000, 'method' => 'especes']);
        $this->postJson($b.'/settlement', $refund)->assertCreated();
        $this->postJson($b.'/settlement', $refund)->assertOk()->assertJsonPath('replayed', true);
        $pay = ['amount_centimes' => 45000, 'paid_on' => '2026-10-22', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
        $this->postJson("$mb/$entry/payments", $pay)->assertCreated();
        $this->postJson("$mb/$entry/payments", $pay)->assertOk();
        $this->getJson('/api/v1/expenses')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 65000)->assertJsonPath('summary.paid_centimes', 65000)->assertJsonPath('summary.balance_centimes', 0);
        $this->getJson('/api/v1/reports?month=2026-10')->assertOk()->assertJsonPath('summary.incoming_centimes', 270000)->assertJsonPath('summary.outgoing_centimes', 65000)->assertJsonPath('summary.net_centimes', 205000);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('maintenance_payments', 2);
        $this->assertDatabaseCount('rental_invoices', 1);
        $this->assertDatabaseCount('rental_pickups', 1);
        $this->assertDatabaseCount('rental_returns', 1);
        $this->assertSame($original,$this->get($b.'/departure/contract/files/'.$file->id.'/content')->assertOk()->streamedContent());
        $this->assertSame($invoiceBytes,$this->get($b.'/invoice/content')->assertOk()->getContent());
    }
}
