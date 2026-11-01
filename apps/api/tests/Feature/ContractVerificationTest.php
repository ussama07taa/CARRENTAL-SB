<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\ContractVerification;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, function () use ($company) {
            $actor = CompanyMembership::factory()->responsible()->create();
            $client = Customer::factory()->create();
            $vehicle = Vehicle::factory()->create();
            $r = Reservation::create(['customer_id' => $client->id, 'vehicle_id' => $vehicle->id, 'actor_membership_id' => $actor->id, 'status' => 'demande', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(5), 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'request')]);
            $v = $this->version($company->id, $r->id, $actor->id, 1, true);

            return [$company->id, $r->id, $actor->id, $v];
        });
    }

    private function version(int $company, int $reservation, int $actor, int $number, bool $rendered): int
    {
        $id = DB::table('rental_contract_versions')->insertGetId(['company_id' => $company, 'reservation_id' => $reservation, 'actor_membership_id' => $actor, 'version' => $number, 'snapshot' => json_encode(['agency' => ['name' => 'Agence publique'], 'customer' => ['first_name' => 'SECRET-NAME', 'identity_number' => 'SECRET-CIN', 'phone' => 'SECRET-PHONE'], 'vehicle' => ['plate' => 'SECRET-PLATE'], 'language' => 'fr']), 'fingerprint' => hash('sha256', 'version'), 'reason' => 'SECRET-REASON', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'version'), 'created_at' => now()]);
        if ($rendered) {
            DB::table('rental_contract_files')->insert(['company_id' => $company, 'reservation_id' => $reservation, 'contract_id' => $id, 'actor_membership_id' => $actor, 'kind' => 'original', 'path' => 'SECRET-PATH', 'mime' => 'application/pdf', 'size' => 20, 'sha256' => hash('sha256', 'pdf'), 'reason' => 'Original', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'file'), 'created_at' => now()]);
        }

        return $id;
    }

    public function test_anonymous_verification_is_allowlisted_read_only_and_not_indexable(): void
    {
        [$company,,,$id] = $this->fixture();
        $token = app(ContractVerification::class)->token($company, $id);
        $before = DB::table('reservation_events')->count();
        $response = $this->get('/verify-contract/'.$token)->assertOk()->assertSee('Agence publique')->assertSee('Dernière version émise enregistrée')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        foreach (['SECRET-NAME', 'SECRET-CIN', 'SECRET-PHONE', 'SECRET-PLATE', 'SECRET-PATH', 'SECRET-REASON'] as $private) {
            $response->assertDontSee($private);
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('reservation_events')->count());
    }

    public function test_only_a_newer_issued_original_changes_the_version_status(): void
    {
        [$company,$reservation,$actor,$id] = $this->fixture();
        $url = '/verify-contract/'.app(ContractVerification::class)->token($company, $id);
        $pending = $this->version($company, $reservation, $actor, 2, false);
        $this->get($url)->assertOk()->assertSee('Dernière version émise enregistrée');
        $this->get('/verify-contract/'.app(ContractVerification::class)->token($company, $pending))->assertNotFound();
        $this->version($company, $reservation, $actor, 3, true);
        $this->get($url)->assertOk()->assertSee('Une version plus récente a été émise');
    }

    public function test_tampering_and_cross_company_links_fail_closed(): void
    {
        [$company,,,$id] = $this->fixture();
        $v = app(ContractVerification::class);
        $token = $v->token($company, $id);
        $this->get('/verify-contract/'.$token.'x')->assertNotFound()->assertDontSee('Agence publique');
        $other = Company::factory()->create();
        $this->get('/verify-contract/'.$v->token($other->id, $id))->assertNotFound();
        $this->get('/verify-contract/'.$v->token($company,99999999))->assertNotFound();
        $this->get('/verify-contract/not-valid')->assertNotFound();
    }
}
