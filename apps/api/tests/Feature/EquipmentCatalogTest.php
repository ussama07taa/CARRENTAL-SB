<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\EquipmentCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EquipmentCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_persists_deduplicates_and_is_tenant_scoped(): void
    {
        $c = Company::factory()->create();
        $m = Tenant::run($c->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);
        $id = $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => 'Chargeur sans fil'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => ' chargeur  sans fil '])->assertCreated()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/vehicles/equipment-catalog')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => 'X', 'company_id' => 99])->assertUnprocessable();
        $other = Company::factory()->create();
        $member = Tenant::run($other->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($member->user)->withSession(['company_id' => $other->id]);
        $this->getJson('/api/v1/vehicles/equipment-catalog')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => 'Chargeur sans fil'])->assertCreated();
        $this->assertDatabaseCount('vehicle_equipment_catalog', 2);
        $this->withSession(['company_id' => null])->getJson('/api/v1/vehicles/equipment-catalog')->assertForbidden();
    }

    public function test_vehicle_selection_is_independent_of_catalog_and_is_saved(): void
    {
        $c = Company::factory()->create();
        $m = Tenant::run($c->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);
        $labels = array_fill_keys(['fr', 'ar', 'en', 'es'], 'Chargeur');
        $id = $this->postJson('/api/v1/vehicles', ['plate' => 'EQ-TEST', 'make' => 'Dacia', 'model' => 'Logan', 'creation_key' => (string) Str::uuid(), 'custom_equipment' => [$labels]])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/vehicles/'.$id)->assertOk()->assertJsonPath('data.custom_equipment.0.fr', 'Chargeur');
        $this->getJson('/api/v1/vehicles/equipment-catalog')->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/vehicles/'.$id, ['plate' => 'EQ-TEST', 'make' => 'Dacia', 'model' => 'Logan', 'version' => 1, 'custom_equipment' => []])->assertOk()->assertJsonCount(0, 'data.custom_equipment');
        $this->getJson('/api/v1/vehicles/equipment-catalog')->assertJsonCount(1, 'data');
    }

    public function test_rename_remove_preserve_vehicle_snapshots_and_do_not_republish_on_save(): void
    {
        $c = Company::factory()->create();
        $m = Tenant::run($c->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);
        $labels = array_fill_keys(['fr', 'ar', 'en', 'es'], 'Chargeur');
        $v = Tenant::run($c->id, fn () => Vehicle::factory()->create(['custom_equipment' => [$labels]]));
        $id = $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => 'Chargeur'])->json('data.id');
        $before = DB::table('vehicles')->where('id', $v->id)->first();
        $this->postJson('/api/v1/vehicles/equipment-catalog/'.$id, ['action' => 'rename', 'expected_name' => 'Chargeur', 'name' => 'Chargeur sans fil'])->assertOk()->assertJsonPath('data.0.name', 'Chargeur sans fil');
        $this->assertEquals($before, DB::table('vehicles')->where('id', $v->id)->first());
        Tenant::run($c->id, fn () => EquipmentCatalog::remember($labels));
        $this->getJson('/api/v1/vehicles/equipment-catalog')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chargeur sans fil');
        $new = DB::table('vehicle_equipment_catalog')->where('company_id', $c->id)->whereNull('archived_at')->value('id');
        $this->postJson('/api/v1/vehicles/equipment-catalog/'.$id, ['action' => 'remove', 'expected_name' => 'Chargeur'])->assertConflict();
        $this->postJson('/api/v1/vehicles/equipment-catalog/'.$new, ['action' => 'rename', 'expected_name' => 'Wrong', 'name' => 'X'])->assertConflict();
        $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => 'GPS additionnel'])->assertCreated();
        $this->postJson('/api/v1/vehicles/equipment-catalog/'.$new, ['action' => 'rename', 'expected_name' => 'Chargeur sans fil', 'name' => 'GPS additionnel'])->assertUnprocessable();
        $this->postJson('/api/v1/vehicles/equipment-catalog/'.$new, ['action' => 'remove', 'expected_name' => 'Chargeur sans fil'])->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals($before, DB::table('vehicles')->where('id', $v->id)->first());
        $this->postJson('/api/v1/vehicles/equipment-catalog', ['name' => 'Chargeur'])->assertCreated();
        $this->getJson('/api/v1/vehicles/equipment-catalog')->assertJsonCount(2, 'data');
        $other = Company::factory()->create();
        $member = Tenant::run($other->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($member->user)->withSession(['company_id' => $other->id]);
        $this->postJson('/api/v1/vehicles/equipment-catalog/'.$id, ['action' => 'remove', 'expected_name' => 'Chargeur'])->assertNotFound();
    }

    public function test_backfill_preserves_vehicle_data_and_localized_labels(): void
    {
        $c = Company::factory()->create();
        $labels = ['fr' => 'Chargeur', 'ar' => 'شاحن', 'en' => 'Charger', 'es' => 'Cargador'];
        $v = Tenant::run($c->id, fn () => Vehicle::factory()->create(['custom_equipment' => [$labels]]));
        $before = DB::table('vehicles')->where('id', $v->id)->first();
        $migration = require database_path('migrations/2026_11_02_180000_create_vehicle_equipment_catalog.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($before, DB::table('vehicles')->where('id', $v->id)->first());
        $this->assertEquals($labels, json_decode(DB::table('vehicle_equipment_catalog')->where('company_id',$c->id)->value('labels'), true));
    }
}
