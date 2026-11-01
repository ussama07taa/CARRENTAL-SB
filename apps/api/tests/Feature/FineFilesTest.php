<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FineFilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupAgency(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $c = Company::factory()->create();
        [$m,$s,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create(), Vehicle::factory()->create()]);
        $this->login($s);

        return [$c, $m, $s, $v];
    }

    private function login($m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function input($v): array
    {
        return ['vehicle_id' => $v->id, 'occurred_local' => '2026-10-20T12:00', 'received_on' => '2026-10-21', 'authority' => 'Organisme TEST', 'reference' => 'PV-TEST-1', 'description' => 'Avis fictif', 'request_key' => (string) Str::uuid()];
    }

    public function test_notice_followup_and_private_documents_are_idempotent_and_tenant_safe(): void
    {
        [$c,$m,$s,$v] = $this->setupAgency();
        $in = $this->input($v);
        $id = $this->postJson('/api/v1/fines', $in)->assertCreated()->json('id');
        $this->postJson('/api/v1/fines', $in)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/fines', [...$in, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/v1/fines', [...$in, 'description' => 'Autre'])->assertConflict();
        $this->assertDatabaseCount('expenses', 0);
        $base = '/api/v1/fines/'.$id;
        $event = ['action' => 'followup', 'status' => 'open', 'next_on' => '2026-11-01', 'note' => 'Vérifier le document', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()];
        $this->postJson($base.'/events', $event)->assertCreated();
        $this->postJson($base.'/events', $event)->assertCreated();
        $this->postJson($base.'/events', [...$event, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('traffic_fine_events', 1);
        $this->postJson($base.'/events', ['action' => 'expense'])->assertForbidden();
        Storage::fake('fine_documents');
        $file = UploadedFile::fake()->createWithContent('avis.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE1cAAAAASUVORK5CYII='));
        $this->post($base.'/documents', ['file' => $file, 'request_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertCreated();
        $this->post($base.'/documents', ['file' => $file, 'request_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseCount('traffic_fine_documents', 1);
        $doc = DB::table('traffic_fine_documents')->first();
        $this->get($base.'/documents/'.$doc->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson($base)->assertJsonPath('data.finance', null)->assertJsonMissingPath('data.documents.0.path');
        $other = Company::factory()->create();
        $foreign = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->login($foreign);
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/documents/'.$doc->id)->assertNotFound();
    }

    public function test_authority_payment_reuses_one_expense_and_staff_cannot_see_money(): void
    {
        [$c,$m,$s,$v] = $this->setupAgency();
        $id = $this->postJson('/api/v1/fines', $this->input($v))->assertCreated()->json('id');
        $this->login($m);
        $e = ['action' => 'expense', 'amount_centimes' => 30000, 'note' => 'Avis vérifié', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()];
        $b = $this->postJson('/api/v1/fines/'.$id.'/events', $e)->assertCreated()->json('data.finance');
        $this->postJson('/api/v1/fines/'.$id.'/events', $e)->assertCreated();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_movements', 0);
        $pay = ['kind' => 'payment', 'amount_centimes' => 30000, 'method' => 'cash', 'occurred_on' => '2026-10-21', 'reason' => 'Paiement fictif', 'confirmed' => true, 'expected_revision' => $b['revision'], 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/expenses/'.$b['id'].'/movements', $pay)->assertCreated();
        $this->postJson('/api/v1/expenses/'.$b['id'].'/movements', $pay)->assertCreated();
        $this->getJson('/api/v1/fines/'.$id)->assertJsonPath('data.finance.paid_centimes', 30000)->assertJsonPath('data.finance.balance_centimes', 0);
        $this->getJson('/api/v1/expenses')->assertJsonPath('total', 1)->assertJsonPath('summary.paid_centimes', 30000);
        $this->assertDatabaseCount('expense_movements', 1);
        $this->login($s);
        $this->getJson('/api/v1/fines/'.$id)->assertJsonPath('data.finance', null)->assertJsonCount(0, 'data.history');
    }
}
