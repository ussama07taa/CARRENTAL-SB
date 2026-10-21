<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceFilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupVehicle(): array
    {
        $c = Company::factory()->create();

        return Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
    }

    private function login($m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function item(array $extra = []): array
    {
        return array_replace(['kind' => 'oil', 'title' => 'Vidange', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000, 'completed_on' => '2026-10-01', 'kilometers' => 45000, 'next_km' => 55000], $extra);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['mode' => 'completed', 'performed_on' => '2026-10-01', 'kilometers' => 45000, 'items' => [$this->item()], 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()], $extra);
    }

    public function test_completed_multiline_file_and_independent_reminders(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $input = $this->input(['items' => [$this->item(), $this->item(['kind' => 'brakes', 'title' => 'Freins', 'amount_centimes' => 120000, 'next_km' => 65000])]]);
        $id = $this->postJson("$url/files", $input)->assertCreated()->json('id');
        $this->postJson("$url/files", $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson("$url/$id/file")->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.cost_centimes', 185000)->assertJsonPath('data.balance_centimes', 165000)->assertJsonPath('data.cost_final', true);
        $this->getJson($url)->assertJsonCount(2, 'reminders');
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->postJson("$url/files", $this->input(['performed_on' => '2026-10-02', 'kilometers' => 55000, 'items' => [$this->item(['next_km' => 65000])]]))->assertCreated();
        $this->getJson($url)->assertJsonCount(2, 'reminders');
    }

    public function test_unknown_price_accepts_advance_then_tracks_final_credit_without_fake_settlement(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson("$url/files", $this->input(['mode' => 'garage', 'items' => [$this->item(['status' => 'pending', 'cost_status' => 'unknown', 'amount_centimes' => null, 'completed_on' => null, 'next_km' => null])], 'paid_centimes' => 50000]))->assertCreated()->json('id');
        $detail = $this->getJson("$url/$id/file")->assertJsonPath('data.cost_final', false)->assertJsonPath('data.unknown_count', 1)->assertJsonPath('data.credit_centimes', 50000)->json('data');
        $work = $detail['items'][0];
        $edit = [...$this->item(['amount_centimes' => 40000, 'next_km' => null]), 'item_key' => $work['item_key'], 'revision' => $detail['revision'], 'reason' => 'Prix final confirmé après diagnostic', 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/work", $edit)->assertCreated();
        $this->postJson("$url/$id/work", $edit)->assertOk();
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_final', true)->assertJsonPath('data.credit_centimes', 10000)->assertJsonCount(2, 'history');
        $this->postJson("$url/$id/payments", ['amount_centimes' => 100, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_unfinished_work_blocks_return_and_deferral_requires_reason(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson("$url/files", $this->input(['mode' => 'garage', 'items' => [$this->item(['status' => 'progress', 'completed_on' => null, 'next_km' => null])]]))->assertCreated()->json('id');
        $return = ['returned_on' => '2026-10-02', 'note' => 'Véhicule vérifié', 'ready' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/return", $return)->assertUnprocessable();
        $d = $this->getJson("$url/$id/file")->json('data');
        $edit = [...$this->item(['status' => 'deferred', 'completed_on' => null, 'next_km' => null]), 'item_key' => $d['items'][0]['item_key'], 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/work", $edit)->assertUnprocessable();
        $this->postJson("$url/$id/work", [...$edit, 'reason' => 'Travail cosmétique reporté, sécurité contrôlée'])->assertCreated();
        $this->postJson("$url/$id/return", $return)->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonPath('data.garage_open', false)->assertJsonPath('data.balance_centimes', 45000);
    }

    public function test_stale_edits_tenant_access_and_append_only_history(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson("$url/files", $this->input())->json('id');
        $d = $this->getJson("$url/$id/file")->json('data');
        $input = [...$this->item(['title' => 'Filtre + huile']), 'item_key' => $d['items'][0]['item_key'], 'revision' => $d['revision'], 'reason' => 'Précision de la prestation', 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/work", $input)->assertCreated();
        $this->postJson("$url/$id/work", [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        [$other,$car] = $this->setupVehicle();
        $this->login($other);
        $this->getJson("$url/$id/file")->assertNotFound();
        $this->postJson("/api/v1/vehicles/$car->id/maintenance/$id/work", $input)->assertNotFound();
        $this->expectException(QueryException::class);
        DB::table('maintenance_work_items')->update(['title' => 'rewrite']);
    }

    public function test_invoice_can_be_added_later_without_changing_cost_and_is_private(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson("$url/files", $this->input())->json('id');
        $file = UploadedFile::fake()->createWithContent('facture.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $input = ['file' => $file, 'request_key' => (string) Str::uuid()];
        $a = $this->postJson("$url/$id/invoices", $input)->assertCreated()->json('id');
        $this->postJson("$url/$id/invoices", $input)->assertCreated()->assertJsonPath('id', $a);
        $this->getJson("$url/$id/file")->assertJsonPath('data.cost_centimes', 65000)->assertJsonCount(1, 'attachments');
        $this->get("$url/$id/invoices/$a")->assertOk()->assertDownload("facture-$a.pdf");
    }

    public function test_legacy_file_and_payments_are_preserved_when_adding_work(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $input = ['kind' => 'oil', 'title' => 'Ancienne vidange', 'performed_on' => '2026-10-01', 'cost_centimes' => 65000, 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'in_garage' => false, 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $before = DB::table('vehicle_maintenance')->find($id);
        $this->postJson("$url/$id/work", [...$this->item(['kind' => 'brakes', 'title' => 'Freins', 'amount_centimes' => 100000]), 'item_key' => null, 'revision' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("$url/$id/file")->assertJsonCount(2, 'data.items')->assertJsonPath('data.cost_centimes', 165000)->assertJsonPath('data.paid_centimes', 20000);
        $this->assertEquals($before, DB::table('vehicle_maintenance')->find($id));
        $this->assertDatabaseCount('maintenance_payments', 1);
    }
}
