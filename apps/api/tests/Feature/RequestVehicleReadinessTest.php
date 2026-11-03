<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestVehicleReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_missing_documents_block_selection_and_creation_then_completed_file_passes(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $company = Company::factory()->create();
        [$m,$c,$v] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $company->id]);
        $url = '/api/v1/reservations/vehicle-check?vehicle_id='.$v->id.'&starts_local=2026-10-22T10:00&ends_local=2026-10-25T10:00';
        $this->getJson($url)->assertOk()->assertJsonPath('ready', false)->assertJsonCount(5, 'issues');
        $input = ['vehicle_id' => $v->id, 'customer_id' => $c->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/reservations', $input)->assertUnprocessable()->assertJsonPath('code', 'VEHICLE_NOT_READY');
        $this->assertDatabaseCount('reservations', 0);
        Tenant::run($company->id, function () use ($v, $m) {
            foreach (VehicleDocument::KINDS as $kind) {
                VehicleDocument::factory()->create(['actor_membership_id' => $m->id, 'vehicle_id' => $v->id, 'kind' => $kind, 'path' => 'test-only.pdf']);
            }
        });
        $this->getJson($url)->assertOk()->assertJsonPath('ready', true);
        $id = $this->postJson('/api/v1/reservations', $input)->assertCreated()->json('data.id');
        Tenant::run($company->id, fn () => VehicleDocument::factory()->create(['actor_membership_id' => $m->id, 'vehicle_id' => $v->id, 'kind' => 'insurance', 'version' => 2, 'effective_on' => '2026-10-01', 'expires_on' => '2026-10-21']));
        $this->getJson($url)->assertOk()->assertJsonPath('ready', false)->assertJsonPath('issues.0.document_kind', 'insurance');
        $this->postJson('/api/v1/reservations', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/reservations', [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $foreign = Company::factory()->create();
        $fv = Tenant::run($foreign->id, fn () => Vehicle::factory()->create());
        $this->getJson('/api/v1/reservations/vehicle-check?vehicle_id='.$fv->id)->assertNotFound();
        $this->postJson('/api/v1/vehicles/'.$v->id.'/maintenance/files', ['mode' => 'garage', 'performed_on' => '2026-10-18', 'items' => [['kind' => 'repair', 'title' => 'Test', 'status' => 'progress', 'cost_status' => 'unknown']], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('ready', false)->assertJsonPath('issues.0.kind','operation');
        $this->assertDatabaseCount('reservations',1);
    }
}
