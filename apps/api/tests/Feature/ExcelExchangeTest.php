<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\ExcelExchange;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ExcelExchangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        } parent::tearDown();
    }

    private function member(bool $responsible = false): CompanyMembership
    {
        $c = Company::factory()->create();

        return Tenant::run($c->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]));
    }

    private function login(CompanyMembership $m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function file(string $kind, array $rows, ?array $headers = null): UploadedFile
    {
        $excel = app(ExcelExchange::class);
        $path = $excel->write($headers ?? array_values($excel->columns($kind)), $rows);
        $this->files[] = $path;

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    private function preview(string $kind, array $rows)
    {
        return $this->post('/api/v1/excel/'.$kind.'/preview', ['file' => $this->file($kind, $rows)], ['Accept' => 'application/json']);
    }

    public function test_employee_import_has_preview_audit_and_idempotent_confirmation(): void
    {
        $m = $this->member();
        $this->login($m);
        $p = $this->preview('vehicles', [['EXCEL-01', 'Dacia', 'Logan', '2024', 'essence', 'manuelle', null, null, '12000', '20/10/2026']])->assertOk()->assertJsonPath('invalid', 0);
        $this->assertDatabaseCount('vehicles', 0);
        $token = $p->json('token');
        $this->postJson('/api/v1/excel/vehicles/confirm', compact('token'))->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/v1/excel/vehicles/confirm', compact('token'))->assertOk();
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseHas('vehicles', ['plate' => 'EXCEL-01', 'fuel' => 'petrol', 'published' => false]);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'excel_imported', 'actor_membership_id' => $m->id]);
        $this->assertSame(1, DB::table('vehicle_events')->where('action', 'excel_imported')->count());
        $this->assertDatabaseCount('vehicle_pricing_rates', 0);
    }

    public function test_customer_import_normalizes_phone_and_flags_name_phone_and_identity_duplicates(): void
    {
        $this->login($this->member(true));
        $rows = [['Salma', 'Test', '0612345678', 'salma@example.test', 'Tanger', null, 'ar', 'cin', 'AB 123', null, null]];
        $token = $this->preview('customers', $rows)->assertOk()->assertJsonPath('invalid', 0)->json('token');
        $this->postJson('/api/v1/excel/customers/confirm', compact('token'))->assertOk();
        $this->assertDatabaseHas('customers', ['phone' => '+212612345678', 'identity_number' => 'AB123', 'language' => 'ar']);
        $this->preview('customers', $rows)->assertOk()->assertJsonPath('invalid', 1)->assertJsonPath('token', null);
        $this->assertDatabaseHas('customer_events', ['action' => 'excel_imported']);
    }

    public function test_invalid_rows_and_in_file_duplicates_block_entire_batch(): void
    {
        $this->login($this->member());
        $this->preview('vehicles', [['A-1', 'Dacia', 'Logan'], ['A 1', 'Dacia', 'Sandero'], ['B-2', null, 'Logan']])->assertOk()->assertJsonPath('invalid', 2)->assertJsonPath('token', null);
        $this->postJson('/api/v1/excel/vehicles/confirm', ['token' => 'tampered'])->assertUnprocessable();
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_duplicate_created_after_preview_rolls_back_whole_batch(): void
    {
        $m = $this->member();
        $this->login($m);
        $token = $this->preview('vehicles', [['NEW', 'Dacia', 'Logan'], ['RACE', 'Dacia', 'Logan']])->assertOk()->json('token');
        Tenant::run($m->company_id, fn () => Vehicle::factory()->create(['plate' => 'RACE', 'plate_key' => 'RACE']));
        $this->postJson('/api/v1/excel/vehicles/confirm', compact('token'))->assertConflict();
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseMissing('vehicles', ['plate' => 'NEW']);
    }

    public function test_token_cannot_cross_user_agency_or_resource_and_expiry_is_checked(): void
    {
        $m = $this->member();
        $this->login($m);
        $token = $this->preview('vehicles', [['PRIVATE', 'Dacia', 'Logan']])->assertOk()->json('token');
        $this->postJson('/api/v1/excel/customers/confirm', compact('token'))->assertForbidden();
        $this->login($this->member());
        $this->postJson('/api/v1/excel/vehicles/confirm', compact('token'))->assertForbidden();
        $this->login($m);
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/excel/vehicles/confirm', compact('token'))->assertUnprocessable();
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_extra_price_columns_are_rejected_and_authentication_is_required(): void
    {
        $this->getJson('/api/v1/excel/vehicles/export')->assertUnauthorized();
        $m = $this->member();
        $this->actingAs($m->user)->getJson('/api/v1/excel/vehicles/template')->assertForbidden();
        $this->login($m);
        $headers = [...array_values(app(ExcelExchange::class)->columns('vehicles')), 'initial_daily_centimes'];
        $this->post('/api/v1/excel/vehicles/preview', ['file' => $this->file('vehicles', [['A', 'B', 'C', null, null, null, null, null, null, null, 100]], $headers)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->getJson('/api/v1/excel/vehicles/template')->assertOk();
    }

    public function test_export_uses_all_filtered_pages_and_excludes_other_agencies(): void
    {
        $m = $this->member();
        $this->login($m);
        Tenant::run($m->company_id, fn () => Vehicle::factory()->count(17)->create(['make' => 'ExcelOnly']));
        $other = $this->member();
        Tenant::run($other->company_id, fn () => Vehicle::factory()->create(['make' => 'ExcelOnly', 'plate' => 'SECRET']));
        $response = $this->get('/api/v1/excel/vehicles/export?make=ExcelOnly')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->files[] = $path;
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }$reader->close();
        $this->assertCount(18, $rows);
        $this->assertStringNotContainsString('SECRET', json_encode($rows));
        $this->assertSame(17, count(array_unique(array_column(array_slice($rows, 1), 0))));
    }

    public function test_export_strings_do_not_become_formulas_and_upload_formulas_are_rejected(): void
    {
        $m = $this->member();
        $this->login($m);
        Tenant::run($m->company_id, fn () => Customer::factory()->create(['first_name' => '=1+1']));
        $response = $this->get('/api/v1/excel/customers/export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->files[] = $path;
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $xml);
        $file = $this->file('vehicles', [['A', 'B', 'C']]);
        $zip = new \ZipArchive;
        $zip->open($file->getRealPath());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</row>', '<c r="D2"><f>1+1</f><v>2</v></c></row>', $xml));
        $zip->close();
        $this->post('/api/v1/excel/vehicles/preview', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_maintenance_history_import_for_both_roles_preserves_finances_garage_and_odometer(): void
    {
        foreach ([false, true] as $responsible) {
            $m = $this->member($responsible);
            $this->login($m);
            $vehicle = Tenant::run($m->company_id, fn () => Vehicle::factory()->create(['plate' => 'HIST-01', 'plate_key' => 'HIST01', 'current_odometer' => 65000, 'current_odometer_date' => '2026-10-20']));
            $garage = ['mode' => 'garage', 'performed_on' => '2026-10-01', 'items' => [['kind' => 'brakes', 'title' => 'Travail en cours', 'status' => 'progress']], 'request_key' => (string) Str::uuid()];
            if ($responsible) {
                $garage['paid_centimes'] = 0;
                $garage['items'][0]['cost_status'] = 'unknown';
            }
            $garageId = $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/maintenance/files', $garage)->assertCreated()->json('id');
            $version = Tenant::run($m->company_id, fn () => $vehicle->fresh()->version);
            $token = $this->preview('maintenance', [['HIST-01', '01/09/2026', 'vidange', 'Vidange historique', '50000', 'Garage test', 'Historique uniquement']])->assertOk()->assertJsonPath('invalid', 0)->json('token');
            $this->assertSame(0, DB::table('vehicle_events')->where('company_id', $m->company_id)->where('action', 'maintenance_excel_imported')->count());
            $first = $this->postJson('/api/v1/excel/maintenance/confirm', compact('token'))->assertOk()->assertJsonPath('count', 1);
            $this->postJson('/api/v1/excel/maintenance/confirm', compact('token'))->assertOk()->assertJsonPath('ids', $first->json('ids'));
            $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'current_odometer' => 65000, 'version' => $version]);
            $this->assertDatabaseHas('vehicle_maintenance', ['id' => $garageId, 'in_garage' => true]);
            $this->assertDatabaseHas('maintenance_work_items', ['maintenance_id' => $first->json('ids.0'), 'status' => 'done', 'cost_status' => 'unknown', 'amount_centimes' => null, 'completed_on' => '2026-09-01']);
            $this->assertSame(1, DB::table('vehicle_events')->where('company_id', $m->company_id)->where('action', 'maintenance_excel_imported')->count());
            $this->preview('maintenance', [['HIST-01', '01/09/2026', 'vidange', 'Vidange historique']])->assertOk()->assertJsonPath('invalid', 1)->assertJsonPath('token', null);
            $this->getJson('/api/v1/maintenance/overview?tab=files')->assertOk()->assertJsonPath('total', 2);
        }
        foreach (['maintenance_payments', 'maintenance_returns', 'maintenance_readiness', 'vehicle_odometer_readings', 'expenses'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_maintenance_unknown_vehicle_future_date_duplicate_and_financial_columns_are_blocked(): void
    {
        $m = $this->member();
        $this->login($m);
        Tenant::run($m->company_id, fn () => Vehicle::factory()->create(['plate' => 'HIST-02', 'plate_key' => 'HIST02']));
        $other = $this->member();
        Tenant::run($other->company_id, fn () => Vehicle::factory()->create(['plate' => 'PRIVATE', 'plate_key' => 'PRIVATE']));
        $this->preview('maintenance', [
            ['PRIVATE', '01/09/2026', 'vidange', 'Non accessible'],
            ['HIST-02', '01/01/2099', 'vidange', 'Date future'],
            ['HIST-02', '01/09/2026', 'vidange', 'Historique'],
            ['HIST 02', '01/09/2026', 'vidange', 'Historique'],
        ])->assertOk()->assertJsonPath('invalid', 3)->assertJsonPath('token', null);
        $headers = [...array_values(app(ExcelExchange::class)->columns('maintenance')), 'amount_centimes'];
        $this->post('/api/v1/excel/maintenance/preview', ['file' => $this->file('maintenance', [['HIST-02', '01/09/2026', 'vidange', 'Historique', null, null, null, 100]], $headers)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_maintenance', 0);
        $this->getJson('/api/v1/excel/maintenance/export')->assertOk();
    }

    public function test_maintenance_race_rechecks_whole_batch_and_does_not_reassign_vehicle(): void
    {
        $m = $this->member();
        $this->login($m);
        $v = Tenant::run($m->company_id, fn () => Vehicle::factory()->create(['plate' => 'HIST-03', 'plate_key' => 'HIST03']));
        $rows = [['HIST-03', '01/09/2026', 'vidange', 'Historique']];
        $tokenA = $this->preview('maintenance', $rows)->assertOk()->json('token');
        $tokenB = $this->preview('maintenance', [['HIST-03', '02/09/2026', 'pneus', 'Pneus'], ...$rows])->assertOk()->json('token');
        $this->postJson('/api/v1/excel/maintenance/confirm', ['token' => $tokenA])->assertOk();
        $this->postJson('/api/v1/excel/maintenance/confirm', ['token' => $tokenB])->assertConflict();
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $token = $this->preview('maintenance', [['HIST-03', '03/09/2026', 'freins', 'Freins']])->assertOk()->json('token');
        Tenant::run($m->company_id, function () use ($v) {
            $v->plate = 'NEW';
            $v->plate_key = 'NEW';
            $v->save();
            Vehicle::factory()->create(['plate' => 'HIST-03', 'plate_key' => 'HIST03']);
        });
        $this->postJson('/api/v1/excel/maintenance/confirm', compact('token'))->assertConflict();
        $this->assertDatabaseCount('vehicle_maintenance', 1);
    }

    public function test_maintenance_export_respects_role_filters_and_all_pages(): void
    {
        $m = $this->member(true);
        $this->login($m);
        $v = Tenant::run($m->company_id, fn () => Vehicle::factory()->create(['plate' => 'EXPORT-MAINT', 'plate_key' => 'EXPORTMAINT']));
        $rows = [];
        for ($i = 1; $i <= 22; $i++) {
            $rows[] = ['EXPORT-MAINT', '01/09/2026', 'vidange', 'Entretien '.$i];
        }
        $token = $this->preview('maintenance', $rows)->assertOk()->json('token');
        $this->postJson('/api/v1/excel/maintenance/confirm', compact('token'))->assertOk();
        $read = function ($url) {
            $response = $this->get($url)->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $this->files[] = $path;
            $reader = new Reader;
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }$reader->close();

            return $rows;
        };
        $rows = $read('/api/v1/excel/maintenance/export?tab=files&q=EXPORT-MAINT');
        $this->assertCount(23, $rows);
        $this->assertContains('Net payé (MAD)', $rows[0]);
        $this->assertSame(22, count(array_unique(array_column(array_slice($rows, 1), 0))));
        $this->assertCount(1, $read('/api/v1/excel/maintenance/export?tab=files&q=NOTFOUND'));
        $staff = Tenant::run($m->company_id, fn () => CompanyMembership::factory()->create());
        $this->login($staff);
        $rows = $read('/api/v1/excel/maintenance/export?tab=files');
        $this->assertCount(23, $rows);
        $this->assertNotContains('Net payé (MAD)', $rows[0]);
        $this->assertCount(10, $rows[0]);
        $this->getJson('/api/v1/excel/maintenance/export?tab=payments')->assertForbidden();
        $this->login($this->member());
        $this->assertCount(1,$read('/api/v1/excel/maintenance/export?tab=files'));
    }
}
