<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalInvoicePreviewTest extends TestCase
{
    use \Tests\Support\StartsAfterVehicleSelection;
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $company = Company::factory()->create();
        [$member,$client,$car] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(['first_name' => 'Client <script>TEST</script>']), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2027-01-22T10:00', 'ends_local' => '2027-01-24T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        return [$company, $member, $client, $id];
    }

    public function test_preview_preserves_agreed_total_unknown_tax_and_does_not_write_money(): void
    {
        [,,,$id] = $this->fixture();
        $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 80000, 'reason' => 'Prix accepté', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $events = DB::table('reservation_events')->count();
        for ($i = 0; $i < 2; $i++) {
            $r = $this->getJson("/api/v1/reservations/$id/invoice-preview")->assertOk()->assertJsonPath('total_centimes', 80000)->assertJsonPath('tax_status', 'unconfirmed')->assertJsonPath('issuance_enabled', false);
            $this->assertStringContainsString('800,00 DH', $r->json('html'));
            $this->assertStringContainsString('BROUILLON', $r->json('html'));
            $this->assertStringContainsString('&lt;script&gt;', $r->json('html'));
            $this->assertStringNotContainsString('<script>TEST', $r->json('html'));
        }
        $this->assertDatabaseCount('rental_payments', 0);
        $this->assertSame($events, DB::table('reservation_events')->count());
    }

    public function test_preview_uses_saved_tax_and_cannot_override_it(): void
    {
        [,,,$id] = $this->fixture();
        $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 120000, 'vat_mode' => 'added', 'vat_rate' => '20', 'reason' => 'Prix accepté', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("/api/v1/reservations/$id/invoice-preview")->assertOk()->assertJsonPath('total_centimes',144000)->assertJsonPath('tax.net_centimes',120000)->assertJsonPath('tax.vat_centimes',24000)->assertJsonPath('tax.gross_centimes',144000);
        $this->getJson("/api/v1/reservations/$id/invoice-preview?vat_rate=30")->assertUnprocessable();
        $this->assertDatabaseCount('rental_payments',0);
    }

    public function test_employee_and_foreign_tenant_cannot_read_preview(): void
    {
        [$company,,, $id] = $this->fixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user)->getJson("/api/v1/reservations/$id/invoice-preview")->assertForbidden();
        $other = Company::factory()->create();
        $manager = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($manager->user)->withSession(['company_id' => $other->id])->getJson("/api/v1/reservations/$id/invoice-preview")->assertNotFound();
    }

    public function test_unpriced_file_cannot_create_preview(): void
    {
        [,,,$id] = $this->fixture();
        $this->getJson("/api/v1/reservations/$id/invoice-preview")->assertUnprocessable();
    }

    public function test_real_pdf_french_and_arabic(): void
    {
        if (! config('rental_contracts.chrome')) {
            $this->markTestSkipped('Chrome required');
        }
        [,,,$id] = $this->fixture();
        $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 80000, 'reason' => 'Prix accepté', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        foreach (['fr', 'ar'] as $language) {
            DB::table('customers')->update(['language' => $language]);
            $response = $this->get("/api/v1/reservations/$id/invoice-preview?format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            if ($out = getenv('PDF_QA_OUTPUT')) {
                if (! is_dir($out)) {
                    mkdir($out, 0700, true);
                } file_put_contents("$out/invoice-$language.pdf", $response->getContent());
            }
        }
    }
}
