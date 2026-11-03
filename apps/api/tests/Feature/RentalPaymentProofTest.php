<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

class RentalPaymentProofTest extends TestCase
{
    use \Tests\Support\StartsAfterVehicleSelection;
    use IsolatedDatabaseMigrations;

    public function test_optional_private_proof_is_idempotent_and_never_verifies_a_transfer(): void
    {
        Storage::fake('payment_proofs');
        $company = Company::factory()->create();
        [$member,$customer,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => now()->addDay()->format('Y-m-d').'T10:00', 'ends_local' => now()->addDays(3)->format('Y-m-d').'T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $receipt = ['amount_centimes' => 10000, 'method' => 'virement', 'received_on' => now()->toDateString(), 'reference' => 'TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
        $response = $this->postJson("/api/v1/reservations/$id/payments", $receipt)->assertCreated();
        $payment = $response->json('payment_id');
        $this->assertNotNull($payment);
        $response->assertJsonPath('financial.verified_centimes', 0)->assertJsonPath('financial.pending_centimes', 10000);
        $this->postJson("/api/v1/reservations/$id/payments", $receipt)->assertOk()->assertJsonPath('payment_id', $payment);
        $url = "/api/v1/reservations/$id/payments/$payment/proofs";
        $this->postJson($url, ['request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('bad.jpg', 'not an image')])->assertUnprocessable();
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('large.pdf', 6000, 'application/pdf')])->assertUnprocessable();
        $key = (string) Str::uuid();
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS1cAAAAASUVORK5CYII=');
        $image = UploadedFile::fake()->createWithContent('proof.png', $bytes);
        $proof = $this->postJson($url, ['request_key' => $key, 'file' => $image])->assertCreated()->json('id');
        $this->postJson($url, ['request_key' => $key, 'file' => $image])->assertOk()->assertJsonPath('id', $proof);
        $this->assertSame(1, DB::table('rental_payment_proofs')->count());
        $this->assertSame(0, DB::table('rental_payment_verifications')->count());
        $this->assertSame(1, DB::table('rental_payments')->count());
        $this->get("$url/$proof")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson($url, ['request_key' => $key, 'file' => UploadedFile::fake()->createWithContent('other.png', $bytes.'different')])->assertConflict();
        $card = $this->postJson("/api/v1/reservations/$id/payments", [...$receipt, 'method' => 'carte', 'request_key' => (string) Str::uuid()])->assertCreated()->json('payment_id');
        $this->postJson("/api/v1/reservations/$id/payments/$card/proofs", ['request_key' => (string) Str::uuid(), 'file' => $image])->assertCreated();
        $cash = $this->postJson("/api/v1/reservations/$id/payments", [...$receipt, 'method' => 'especes', 'request_key' => (string) Str::uuid()])->assertCreated()->json('payment_id');
        $this->postJson("/api/v1/reservations/$id/payments/$cash/proofs", ['request_key' => (string) Str::uuid(), 'file' => $image])->assertUnprocessable();
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('TEST disk unavailable'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('payment_proofs')->andReturn($disk);
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'file' => $image])->assertStatus(500);
        $this->assertSame(3, DB::table('rental_payments')->count());
        $this->assertSame(2, DB::table('rental_payment_proofs')->count());
        $other = Company::factory()->create();
        $outsider = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($outsider->user)->withSession(['company_id' => $other->id]);
        $this->get("$url/$proof")->assertNotFound();
        $this->postJson($url,['request_key' => (string) Str::uuid(), 'file' => $image])->assertNotFound();
    }
}
