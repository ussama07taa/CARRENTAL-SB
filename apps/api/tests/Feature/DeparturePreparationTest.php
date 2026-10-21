<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeparturePreparationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $confirmed = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
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

    private function command(int $id, string $action, array $values = [], ?string $key = null)
    {
        return $this->postJson("/api/v1/reservations/$id/departure", ['action' => $action, 'reason' => 'Contrôle ou réception réelle · test', 'request_key' => $key ?? (string) Str::uuid(), ...$values]);
    }

    private function read(int $id)
    {
        return $this->getJson("/api/v1/reservations/$id/departure");
    }

    public function test_read_is_non_mutating_and_does_not_claim_pickup_ready(): void
    {
        [,,,, $id] = $this->fixture();
        $events = DB::table('reservation_events')->count();
        $this->read($id)->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.documents.verified', false)->assertJsonPath('data.caution.covered', false)->assertJsonPath('data.can_pick_up', false)->assertJsonPath('data.inspection_done', false)->assertJsonPath('data.contract_signed', false);
        $this->assertDatabaseCount('reservation_departure_entries', 0);
        $this->assertDatabaseCount('reservation_events', $events);
    }

    public function test_original_verification_invalidates_after_customer_change_and_stale_fields_cannot_overwrite(): void
    {
        [,, $client, , $id] = $this->fixture();
        $fingerprint = $this->read($id)->json('data.documents.fingerprint');
        $this->command($id, 'documents_checked', ['fingerprint' => $fingerprint, 'identity_original' => false, 'licence_original' => true])->assertUnprocessable();
        $this->command($id, 'documents_checked', ['fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true])->assertCreated()->assertJsonPath('data.documents.verified', true);
        $values = ['version' => $client->version, 'identity_type' => 'cin', 'identity_number' => 'TEST456', 'licence_number' => 'LIC456', 'licence_expires_on' => '2030-02-01'];
        $this->putJson("/api/v1/reservations/$id/departure/customer", $values)->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $client->id, 'phone' => $client->phone]);
        $this->read($id)->assertJsonPath('data.documents.verified', false);
        $this->command($id, 'documents_checked', ['fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true])->assertConflict();
        $this->putJson("/api/v1/reservations/$id/departure/customer", $values)->assertConflict();
    }

    public function test_expired_licence_and_unconfirmed_request_cannot_be_checked(): void
    {
        [,, $client, , $id] = $this->fixture();
        $this->putJson("/api/v1/reservations/$id/departure/customer", ['version' => $client->version, 'identity_type' => 'cin', 'identity_number' => 'TEST123', 'licence_number' => 'LIC123', 'licence_expires_on' => '2026-10-21'])->assertOk();
        $fingerprint = $this->read($id)->json('data.documents.fingerprint');
        $this->command($id, 'documents_checked', ['fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true])->assertUnprocessable();
        [,,,, $other] = $this->fixture(false);
        $this->command($other, 'caution_set', ['amount_centimes' => 500000, 'expected_terms_id' => null])->assertUnprocessable();
    }

    public function test_partial_deposit_pending_transfer_and_replay_do_not_change_rental_money(): void
    {
        [,,,, $id] = $this->fixture();
        $this->command($id, 'caution_set', ['amount_centimes' => 500000, 'expected_terms_id' => null])->assertCreated();
        $key = (string) Str::uuid();
        $cash = ['amount_centimes' => 200000, 'method' => 'especes', 'received_on' => '2026-10-19', 'receipt_attested' => true];
        $this->command($id, 'caution_received', $cash, $key)->assertCreated()->assertJsonPath('data.caution.remaining_centimes', 300000);
        $this->command($id, 'caution_received', $cash, $key)->assertOk()->assertJsonPath('replayed', true);
        $receipt = $this->command($id, 'caution_received', ['amount_centimes' => 300000, 'method' => 'virement', 'received_on' => '2026-10-19', 'reference' => 'TEST-VIR', 'receipt_attested' => true])->assertCreated()->assertJsonPath('data.caution.pending_centimes', 300000)->assertJsonPath('data.caution.covered', false)->json('data.caution.receipts.1.id');
        $this->command($id, 'caution_verified', ['receipt_id' => $receipt, 'receipt_attested' => true])->assertCreated()->assertJsonPath('data.caution.covered', true)->assertJsonPath('data.caution.verified_centimes', 500000)->assertJsonPath('data.can_pick_up', false);
        $this->command($id, 'caution_verified', ['receipt_id' => $receipt, 'receipt_attested' => true])->assertConflict();
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('financial.total_centimes', 270000)->assertJsonPath('financial.verified_centimes', 270000)->assertJsonCount(1, 'financial.payments')->assertJsonPath('data.status', 'confirmee');
        $this->assertSame(2, DB::table('reservation_departure_entries')->where('action', 'caution_received')->count());
    }

    public function test_employee_amount_permission_responsible_waiver_and_tenant_isolation(): void
    {
        [$company, $actor, , , $id] = $this->fixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $terms = $this->command($id, 'caution_set', ['amount_centimes' => 100000, 'expected_terms_id' => null])->assertCreated()->json('data.caution.terms_id');
        $this->command($id, 'caution_set', ['amount_centimes' => 200000, 'expected_terms_id' => null])->assertConflict();
        $receipt = $this->command($id, 'caution_received', ['amount_centimes' => 100000, 'method' => 'virement', 'received_on' => '2026-10-19', 'reference' => 'TEST', 'receipt_attested' => true])->assertCreated()->json('data.caution.receipts.0.id');
        $this->command($id, 'caution_waived', ['expected_terms_id' => $terms])->assertForbidden();
        $this->command($id, 'caution_verified', ['receipt_id' => $receipt, 'receipt_attested' => true])->assertForbidden();
        $this->read($id)->assertJsonPath('data.can_manage_documents', false)->assertJsonCount(0, 'data.documents.files');
        $this->actingAs($actor->user);
        $this->command($id, 'caution_waived', ['expected_terms_id' => $terms, 'reason' => ''])->assertUnprocessable();
        $this->command($id, 'caution_waived', ['expected_terms_id' => $terms])->assertCreated()->assertJsonPath('data.caution.waived', true);
        $this->fixture();
        $this->read($id)->assertNotFound();
        $this->command($id, 'caution_set', ['amount_centimes' => 100000, 'expected_terms_id' => null])->assertNotFound();
    }

    public function test_ledger_is_append_only(): void
    {
        [,,,, $id] = $this->fixture();
        $this->command($id, 'caution_set', ['amount_centimes' => 100000, 'expected_terms_id' => null])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('reservation_departure_entries')->update(['amount_centimes' => 1]);
    }

    public function test_saved_client_photos_are_reused_and_replacement_preserves_original_and_requires_new_check(): void
    {
        Storage::fake('customer_documents');
        [,, $client, $car, $first] = $this->fixture();
        $image = fn () => UploadedFile::fake()->createWithContent('test-identity.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $url = "/api/v1/customers/{$client->id}/documents";
        $original = $this->postJson($url, ['image' => $image(), 'slot' => 'identity_front', 'previous_id' => null, 'upload_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $fingerprint = $this->read($first)->assertJsonPath('data.documents.files.0.id', $original)->json('data.documents.fingerprint');
        $this->command($first, 'documents_checked', ['fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true])->assertCreated();
        $second = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-26T10:00', 'ends_local' => '2026-10-29T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->read($second)->assertJsonPath('data.documents.files.0.id', $original)->assertJsonPath('data.documents.verified', false);
        $this->assertDatabaseCount('customer_documents', 1);
        $replacement = $this->postJson($url, ['image' => $image(), 'slot' => 'identity_front', 'previous_id' => $original, 'upload_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->read($first)->assertJsonPath('data.documents.files.0.id', $replacement)->assertJsonPath('data.documents.verified', false);
        $this->read($second)->assertJsonPath('data.documents.files.0.id', $replacement)->assertJsonPath('data.documents.verified', false);
        $this->get("$url/$original/content")->assertOk();
        $this->getJson("/api/v1/customers/{$client->id}")->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.id', $replacement);
        $this->assertDatabaseCount('customer_documents', 2);
    }
}
