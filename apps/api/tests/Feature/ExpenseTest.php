<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenanceOverview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->setDate(2026, 10, 24));
        $c = Company::factory()->create();
        [$m,$s,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create(), Vehicle::factory()->create()]);
        $this->login($m);

        return [$c, $m, $s, $v];
    }

    private function login(CompanyMembership $m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['title' => 'Lavage de test', 'category' => 'wash', 'supplier' => 'Station TEST', 'invoice_reference' => 'TEST-F001', 'incurred_on' => '2026-10-20', 'due_on' => '2026-10-23', 'amount_centimes' => 30000, 'allocations' => [['vehicle_id' => null, 'amount_centimes' => 30000]], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()], $extra);
    }

    private function current(int $id): array
    {
        return $this->getJson('/api/v1/expenses/'.$id)->assertOk()->json('data');
    }

    private function movement(int $id, array $extra = []): array
    {
        return array_replace(['kind' => 'payment', 'amount_centimes' => 10000, 'method' => 'cash', 'occurred_on' => '2026-10-22', 'reason' => 'Opération fictive vérifiée', 'confirmed' => true, 'expected_revision' => $this->current($id)['revision'], 'request_key' => (string) Str::uuid()], $extra);
    }

    private function create(array $extra = []): int
    {
        return $this->postJson('/api/v1/expenses', $this->input($extra))->assertCreated()->json('id');
    }

    public function test_manager_export_uses_all_filtered_pages_and_blocks_staff_and_cross_tenant_data(): void
    {
        [, $manager, $staff] = $this->agency();
        for ($i = 0; $i < 21; $i++) {
            $this->create(['title' => '=SUM(1,2)', 'invoice_reference' => 'EXPORT-'.$i]);
        }
        $this->create(['category' => 'fuel', 'invoice_reference' => 'NOT-EXPORTED']);
        $this->create(['incurred_on' => '2026-09-30', 'invoice_reference' => 'OLD-NOT-EXPORTED']);
        $response = $this->get('/api/v1/expenses/export?category=wash&from=2026-10-01&to=2026-10-31&document=missing&page=2')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $reader = new \OpenSpout\Reader\XLSX\Reader;
            $reader->open($path);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
                $sheets[$sheet->getName()] = $rows;
            }
            $reader->close();
            $this->assertCount(22, $sheets['Dépenses']);
            $this->assertSame('=SUM(1,2)', $sheets['Dépenses'][1][2]);
            $this->assertSame('300,00', $sheets['Dépenses'][1][10]);
            $this->assertSame('Manquant', $sheets['Dépenses'][1][16]);
            $this->assertStringContainsString('6300,00 MAD', json_encode($sheets['Instructions']));
            $zip = new \ZipArchive;
            $zip->open($path);
            $this->assertDoesNotMatchRegularExpression('/<f[ >]/', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
        } finally {
            @unlink($path);
        }
        $this->login($staff);
        $this->getJson('/api/v1/expenses/export')->assertForbidden();
        $this->agency();
        $empty = $this->get('/api/v1/expenses/export')->assertOk();
        $reader = new \OpenSpout\Reader\XLSX\Reader;
        $path = $empty->baseResponse->getFile()->getPathname();
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() === 'Dépenses') {
                $this->assertCount(1, iterator_to_array($sheet->getRowIterator()));
            }
        }
        $reader->close();
        @unlink($path);
        $this->login($manager);
        $this->getJson('/api/v1/expenses/export?category=bad')->assertUnprocessable();
    }

    public function test_document_category_and_date_filters_follow_uploads_and_full_settlement(): void
    {
        [,$manager,,$vehicle] = $this->agency();
        Storage::fake('expense_documents');
        Storage::fake('vehicle_documents');
        $id = $this->create(['paid_centimes' => 10000, 'paid_on' => '2026-10-21', 'method' => 'cash']);
        $this->create(['category' => 'fuel', 'invoice_reference' => 'FUEL-TEST']);
        $this->create(['invoice_reference' => 'SEPT-TEST', 'incurred_on' => '2026-09-30']);
        $url = '/api/v1/expenses?category=wash&from=2026-10-01&to=2026-10-31&document=missing';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.has_document', false)->assertJsonPath('summary.confirmed_cost_centimes', 30000)->assertJsonPath('summary.paid_centimes', 10000)->assertJsonPath('summary.balance_centimes', 20000);
        $this->post("/api/v1/expenses/$id/documents", ['file' => UploadedFile::fake()->createWithContent('invoice.png', file_get_contents(base_path('tests/Fixtures/signature.png'))), 'request_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson($url)->assertJsonPath('total', 0)->assertJsonPath('summary.balance_centimes', 0);
        $this->getJson(str_replace('missing', 'attached', $url))->assertJsonPath('total', 1)->assertJsonPath('data.0.has_document', true);
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id, ['amount_centimes' => 20000]))->assertCreated();
        $this->getJson(str_replace('missing', 'attached', $url).'&tab=paid')->assertJsonPath('total', 1)->assertJsonPath('summary.paid_centimes', 30000)->assertJsonPath('summary.balance_centimes', 0);
        $this->assertCount(2, $this->current($id)['movements']);
        $base = "/api/v1/vehicles/$vehicle->id/maintenance";
        $maintenance = $this->postJson($base.'/files', $this->maintenanceInput())->assertCreated()->json('id');
        $this->getJson('/api/v1/expenses?category=maintenance&document=missing')->assertJsonPath('total', 1)->assertJsonPath('data.0.has_document', false);
        $this->post("$base/$maintenance/invoices", ['file' => UploadedFile::fake()->createWithContent('garage.png', file_get_contents(base_path('tests/Fixtures/signature.png'))), 'request_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertSuccessful();
        $this->getJson('/api/v1/expenses?category=maintenance&document=missing')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/expenses?category=maintenance&document=attached')->assertJsonPath('total', 1)->assertJsonPath('data.0.has_document', true);
        $this->getJson('/api/v1/expenses?category=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/expenses?document=unknown')->assertUnprocessable();
        $this->agency();
        $this->getJson('/api/v1/expenses?document=attached')->assertJsonPath('total', 0);
        $this->login($manager);
        $this->getJson('/api/v1/expenses?document=attached')->assertJsonPath('total', 2);
    }

    public function test_shared_invoice_and_partial_payment_have_one_total_and_tenant_safe_allocations(): void
    {
        [$c,,,$v] = $this->agency();
        $v2 = Tenant::run($c->id, fn () => Vehicle::factory()->create());
        $input = $this->input(['amount_centimes' => 120000, 'allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 90000], ['vehicle_id' => $v2->id, 'amount_centimes' => 30000]], 'paid_centimes' => 20000, 'paid_on' => '2026-10-21', 'method' => 'transfer']);
        $id = $this->postJson('/api/v1/expenses', $input)->assertCreated()->json('id');
        $this->postJson('/api/v1/expenses', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/expenses')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 120000)->assertJsonPath('summary.paid_centimes', 20000)->assertJsonPath('summary.balance_centimes', 100000);
        $this->getJson('/api/v1/expenses?vehicle_id='.$v2->id)->assertJsonPath('total', 1)->assertJsonPath('data.0.allocations.1.amount_centimes', 30000);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_movements', 1);
        $this->postJson('/api/v1/expenses', array_replace($input, ['amount_centimes' => 130000]))->assertConflict();
        $this->postJson('/api/v1/expenses', $this->input(['allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 20000]]]))->assertUnprocessable();
        $this->postJson('/api/v1/expenses', $this->input(['allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 15000], ['vehicle_id' => $v->id, 'amount_centimes' => 15000]]]))->assertUnprocessable();
    }

    public function test_maintenance_is_live_source_including_old_payments_without_financial_copies(): void
    {
        [,,,$v] = $this->agency();
        $base = "/api/v1/vehicles/$v->id/maintenance";
        $entry = $this->postJson($base, ['kind' => 'oil', 'title' => 'Vidange TEST', 'garage' => 'Garage TEST', 'performed_on' => '2026-10-20', 'cost_centimes' => 65000, 'paid_centimes' => 20000, 'paid_on' => '2026-10-20', 'method' => 'cash', 'in_garage' => false, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->getJson('/api/v1/expenses')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.source', 'maintenance')->assertJsonPath('summary.confirmed_cost_centimes', 65000)->assertJsonPath('summary.paid_centimes', 20000)->assertJsonPath('summary.balance_centimes', 45000);
        $pay = ['amount_centimes' => 45000, 'paid_on' => '2026-10-22', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
        $this->postJson("$base/$entry/payments", $pay)->assertCreated();
        $this->postJson("$base/$entry/payments", $pay)->assertOk();
        $this->getJson('/api/v1/expenses?tab=paid')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 65000)->assertJsonPath('summary.paid_centimes', 65000)->assertJsonPath('summary.balance_centimes', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('expense_movements', 0);
        $this->assertDatabaseCount('maintenance_payments', 2);
        $this->postJson('/api/v1/expenses', $this->input(['source' => 'maintenance', 'maintenance_id' => $entry]))->assertUnprocessable();
    }

    public function test_unknown_and_estimated_maintenance_do_not_become_confirmed_zero_or_duplicate_costs(): void
    {
        [,,,$v] = $this->agency();
        $base = "/api/v1/vehicles/$v->id/maintenance";
        $entry = $this->postJson("$base/files", ['mode' => 'garage', 'performed_on' => '2026-10-20', 'items' => [['kind' => 'brakes', 'title' => 'Freins TEST', 'status' => 'pending', 'cost_status' => 'unknown', 'amount_centimes' => null]], 'paid_centimes' => 10000, 'paid_on' => '2026-10-20', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->getJson('/api/v1/expenses?tab=pricing')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 0)->assertJsonPath('summary.provisional_files', 1)->assertJsonPath('summary.paid_centimes', 10000)->assertJsonPath('data.0.cost_final', false);
        $d = $this->getJson("$base/$entry/file")->json('data');
        $work = ['item_key' => $d['items'][0]['item_key'], 'kind' => 'brakes', 'title' => 'Freins TEST', 'status' => 'pending', 'cost_status' => 'confirmed', 'amount_centimes' => 30000, 'reason' => 'Prix reçu', 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()];
        $this->postJson("$base/$entry/work", $work)->assertCreated();
        $this->getJson('/api/v1/expenses')->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 30000)->assertJsonPath('summary.paid_centimes', 10000)->assertJsonPath('summary.balance_centimes', 20000);
    }

    public function test_staff_and_other_agencies_cannot_read_or_mutate_expenses_or_documents(): void
    {
        [$c,$m,$s,$v] = $this->agency();
        Storage::fake('expense_documents');
        $id = $this->create();
        $this->login($s);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('can_manage_expenses', false);
        foreach (['/api/v1/expenses', '/api/v1/expenses/'.$id, '/api/v1/expenses/'.$id.'/documents/1'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        foreach (['/api/v1/expenses', '/api/v1/expenses/'.$id, '/api/v1/expenses/'.$id.'/movements', '/api/v1/expenses/'.$id.'/cancel', '/api/v1/expenses/'.$id.'/documents'] as $url) {
            $this->postJson($url, [])->assertForbidden();
        }
        [$other,$owner,, $otherCar] = $this->agency();
        $this->getJson('/api/v1/expenses')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/expenses/'.$id)->assertNotFound();
        $this->postJson('/api/v1/expenses', $this->input(['allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 30000]]]))->assertNotFound();
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_duplicate_reference_warning_requires_explicit_acknowledgement_and_keeps_existing_link(): void
    {
        $this->agency();
        $id = $this->create();
        $input = $this->input();
        $this->postJson('/api/v1/expenses', $input)->assertConflict()->assertJsonPath('code', 'POSSIBLE_DUPLICATE')->assertJsonPath('duplicates.0.id', $id);
        $this->postJson('/api/v1/expenses', [...$input, 'duplicate_confirmed' => true])->assertCreated();
        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_payment_refund_corrections_and_cancellation_preserve_history_and_balance(): void
    {
        $this->agency();
        $id = $this->create();
        $pay = $this->movement($id, ['amount_centimes' => 20000]);
        $this->postJson("/api/v1/expenses/$id/movements", $pay)->assertCreated();
        $this->postJson("/api/v1/expenses/$id/movements", $pay)->assertCreated();
        $refund = $this->movement($id, ['kind' => 'refund', 'amount_centimes' => 5000, 'occurred_on' => '2026-10-23']);
        $this->postJson("/api/v1/expenses/$id/movements", $refund)->assertCreated()->assertJsonPath('data.paid_centimes', 15000);
        $d = $this->current($id);
        $payment = collect($d['movements'])->firstWhere('kind', 'payment');
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id, ['movement_key' => $payment['movement_key'], 'amount_centimes' => 0]))->assertUnprocessable();
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id, ['movement_key' => $payment['movement_key'], 'amount_centimes' => 25000]))->assertCreated()->assertJsonPath('data.paid_centimes', 20000)->assertJsonPath('data.balance_centimes', 10000);
        $d = $this->current($id);
        $this->postJson("/api/v1/expenses/$id/cancel", ['expected_revision' => $d['revision'], 'reason' => 'Facture en double, remboursement attendu', 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.credit_centimes', 20000);
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id, ['kind' => 'refund', 'amount_centimes' => 20000, 'occurred_on' => '2026-10-24']))->assertCreated()->assertJsonPath('data.paid_centimes', 0);
        $this->assertDatabaseCount('expense_movements', 4);
        $this->assertDatabaseCount('expense_versions', 2);
        $this->getJson('/api/v1/expenses')->assertJsonPath('summary.confirmed_cost_centimes', 0)->assertJsonPath('summary.paid_centimes', 0);
    }

    public function test_stale_edits_overpayments_and_refunds_before_payment_are_rejected_atomically(): void
    {
        $this->agency();
        $id = $this->create();
        $old = $this->current($id);
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id, ['amount_centimes' => 40000]))->assertUnprocessable();
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id))->assertCreated();
        $this->postJson("/api/v1/expenses/$id", $this->input(['expected_revision' => $old['revision'], 'reason' => 'Correction']))->assertConflict();
        $this->postJson("/api/v1/expenses/$id/movements", $this->movement($id, ['kind' => 'refund', 'amount_centimes' => 5000, 'occurred_on' => '2026-10-21']))->assertUnprocessable();
        $this->postJson("/api/v1/expenses/$id", $this->input(['expected_revision' => $this->current($id)['revision'], 'reason' => 'Date différente', 'incurred_on' => '2026-10-23']))->assertUnprocessable();
        $this->assertDatabaseCount('expense_movements', 1);
        $this->assertDatabaseCount('expense_versions', 1);
    }

    public function test_private_attachment_is_idempotent_and_hash_checked(): void
    {
        $this->agency();
        Storage::fake('expense_documents');
        $id = $this->create();
        $file = UploadedFile::fake()->createWithContent('facture.png', file_get_contents(base_path('tests/Fixtures/signature.png')));
        $input = ['file' => $file, 'request_key' => (string) Str::uuid()];
        $doc = $this->post("/api/v1/expenses/$id/documents", $input, ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->post("/api/v1/expenses/$id/documents", $input, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('id', $doc);
        $this->get("/api/v1/expenses/$id/documents/$doc")->assertOk();
        $this->assertDatabaseCount('expense_documents', 1);
        $path = DB::table('expense_documents')->value('path');
        Storage::disk('expense_documents')->put($path, 'changed');
        $this->getJson("/api/v1/expenses/$id/documents/$doc")->assertConflict();
    }

    public function test_database_history_is_immutable_and_cross_tenant_allocation_is_rejected(): void
    {
        [$c,$m,,$v] = $this->agency();
        $id = $this->create();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['expenses', 'expense_versions', 'expense_allocations', 'expense_movements', 'expense_documents'] as $table) {
            foreach (["UPDATE $table SET company_id=company_id", "DELETE FROM $table", "TRUNCATE $table CASCADE"] as $sql) {
                DB::beginTransaction();
                try {
                    DB::statement($sql);
                    $this->fail('Immutable history allowed mutation');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                } finally {
                    DB::rollBack();
                }
            }
        }
        $other = Company::factory()->create();
        $otherCar = Tenant::run($other->id, fn () => Vehicle::factory()->create());
        $version = DB::table('expense_versions')->value('id');
        DB::beginTransaction();
        try {
            DB::table('expense_allocations')->insert(['company_id' => $c->id, 'version_id' => $version, 'vehicle_id' => $otherCar->id, 'amount_centimes' => 1]);
            $this->fail('Cross tenant relation allowed');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_cost_correction_replaces_current_total_but_preserves_original_history(): void
    {
        $this->agency();
        $id = $this->create(['paid_centimes' => 30000, 'paid_on' => '2026-10-21', 'method' => 'cash']);
        $input = $this->input(['amount_centimes' => 20000, 'allocations' => [['vehicle_id' => null, 'amount_centimes' => 20000]], 'reason' => 'Facture rectifiée TEST', 'expected_revision' => $this->current($id)['revision']]);
        $this->postJson('/api/v1/expenses/'.$id, $input)->assertCreated()->assertJsonPath('data.credit_centimes', 10000)->assertJsonPath('data.history.0.amount_centimes', 30000);
        $this->postJson('/api/v1/expenses/'.$id, $input)->assertCreated();
        $this->getJson('/api/v1/expenses?to=2026-10-24')->assertOk()->assertJsonPath('summary.confirmed_cost_centimes', 20000)->assertJsonPath('summary.credit_centimes', 10000);
        $this->assertDatabaseCount('expense_versions', 2);
    }

    public function test_shortcut_totals_match_the_filtered_expenses_instead_of_including_hidden_rows(): void
    {
        $this->agency();
        $this->create(['invoice_reference' => 'PAID', 'paid_centimes' => 30000, 'paid_on' => '2026-10-21', 'method' => 'cash']);
        $this->create(['invoice_reference' => 'UNPAID']);
        $this->getJson('/api/v1/expenses?tab=paid')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 30000)->assertJsonPath('summary.paid_centimes', 30000)->assertJsonPath('summary.balance_centimes', 0)->assertJsonPath('counts.all', 2);
        $this->getJson('/api/v1/expenses?tab=unpaid')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 30000)->assertJsonPath('summary.paid_centimes', 0)->assertJsonPath('summary.balance_centimes', 30000);
        $this->getJson('/api/v1/expenses?tab=maintenance')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.confirmed_cost_centimes', 0);
    }

    public function test_database_rejects_a_version_without_a_matching_allocation_total(): void
    {
        $this->agency();
        $id = $this->create();
        $row = (array) DB::table('expense_versions')->where('expense_id', $id)->first();
        unset($row['id']);
        $row['version'] = 2;
        $row['request_key'] = (string) Str::uuid();
        DB::beginTransaction();
        try {
            DB::table('expense_versions')->insert($row);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Missing allocation was accepted');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_failed_payment_rolls_back_complete_creation(): void
    {
        $this->agency();
        DB::unprepared("CREATE FUNCTION fail_expense_payment_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'write failed'; END; $$; CREATE TRIGGER fail_expense_payment_test BEFORE INSERT ON expense_movements FOR EACH ROW EXECUTE FUNCTION fail_expense_payment_test()");
        $this->postJson('/api/v1/expenses', $this->input(['paid_centimes' => 10000, 'paid_on' => '2026-10-21', 'method' => 'cash']))->assertServerError();
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('expense_versions', 0);
        $this->assertDatabaseCount('expense_allocations', 0);
    }

    private function maintenanceInput(array $extra = []): array
    {
        return array_replace(['mode' => 'completed', 'performed_on' => '2026-10-20', 'garage' => 'Garage ONCE',
            'items' => [['kind' => 'oil', 'title' => 'Vidange', 'status' => 'done', 'cost_status' => 'confirmed', 'amount_centimes' => 65000, 'completed_on' => '2026-10-20']],
            'paid_centimes' => 0, 'request_key' => (string) Str::uuid()], $extra);
    }

    public function test_one_save_records_maintenance_and_full_payment_once_with_atomic_validation(): void
    {
        [,,,$v] = $this->agency();
        $url = "/api/v1/vehicles/$v->id/maintenance/files";
        $input = $this->maintenanceInput(['paid_centimes' => 65000, 'paid_on' => '2026-10-20', 'method' => 'cash']);
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/expenses')->assertJsonPath('total', 1)->assertJsonPath('summary.confirmed_cost_centimes', 65000)->assertJsonPath('summary.paid_centimes', 65000)->assertJsonPath('summary.balance_centimes', 0);
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->assertDatabaseCount('expenses', 0);
        $this->postJson($url, array_replace($input, ['request_key' => (string) Str::uuid(), 'paid_centimes' => 66000]))->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_maintenance', 1);
    }

    public function test_duplicate_detection_crosses_maintenance_and_expenses_in_both_directions(): void
    {
        [,,,$v] = $this->agency();
        $url = "/api/v1/vehicles/$v->id/maintenance/files";
        $id = $this->postJson($url, $this->maintenanceInput())->assertCreated()->json('id');
        $input = $this->input(['supplier' => ' garage  once ', 'invoice_reference' => null, 'amount_centimes' => 65000, 'allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 65000]]]);
        $this->postJson('/api/v1/expenses', $input)->assertConflict()->assertJsonPath('duplicates.0.source', 'maintenance')->assertJsonPath('duplicates.0.id', $id)->assertJsonPath('duplicates.0.vehicle_id', $v->id);
        $this->assertDatabaseCount('expenses', 0);
        $manual = $this->create(['supplier' => 'Other garage', 'invoice_reference' => null, 'amount_centimes' => 65000, 'allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 65000]]]);
        $reverse = $this->maintenanceInput(['garage' => 'Other garage']);
        $this->postJson($url, $reverse)->assertConflict()->assertJsonPath('duplicates.0.source', 'manual')->assertJsonPath('duplicates.0.id', $manual);
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->postJson($url, [...$reverse, 'duplicate_confirmed' => true])->assertCreated();
        $this->assertDatabaseCount('vehicle_maintenance', 2);
        $this->postJson($url, $this->maintenanceInput())->assertConflict()->assertJsonPath('duplicates.0.source', 'maintenance');
        $this->postJson($url, $this->maintenanceInput(['performed_on' => '2026-10-21']))->assertCreated();
    }

    public function test_shared_invoice_filter_shows_only_vehicle_cost_but_keeps_whole_invoice_payments(): void
    {
        [$c,,,$v] = $this->agency();
        $other = Tenant::run($c->id, fn () => Vehicle::factory()->create());
        $this->create(['amount_centimes' => 120000, 'allocations' => [['vehicle_id' => $v->id, 'amount_centimes' => 90000], ['vehicle_id' => $other->id, 'amount_centimes' => 30000]], 'paid_centimes' => 100000, 'paid_on' => '2026-10-24', 'method' => 'cash']);
        $this->getJson('/api/v1/expenses?vehicle_id='.$other->id.'&from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('summary.vehicle_cost_centimes', 30000)->assertJsonPath('summary.paid_centimes', 100000)->assertJsonPath('summary.balance_centimes', 20000)->assertJsonPath('data.0.vehicle_cost_centimes', 30000);
        $this->getJson('/api/v1/expenses?from=2026-10-24')->assertJsonPath('total', 0);
    }

    public function test_stale_payment_from_a_second_screen_does_not_record_money_twice(): void
    {
        [,,,$v] = $this->agency();
        $base = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson($base.'/files', $this->maintenanceInput())->assertCreated()->json('id');
        $revision = $this->getJson("$base/$id/file")->json('data.revision');
        $pay = ['amount_centimes' => 10000, 'paid_on' => '2026-10-21', 'method' => 'cash', 'expected_paid_centimes' => 0, 'work_revision' => $revision, 'request_key' => (string) Str::uuid()];
        $this->postJson("$base/$id/payments", $pay)->assertCreated();
        $this->postJson("$base/$id/payments", $pay)->assertOk();
        $this->postJson("$base/$id/payments", [...$pay, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('maintenance_payments', 1);
    }

    public function test_database_paginates_current_values_without_loading_document_or_version_histories(): void
    {
        $this->agency();
        for ($i = 0; $i < 25; $i++) {
            $this->create(['invoice_reference' => 'PAGE-'.$i]);
        }
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/expenses?page=2')->assertOk()->assertJsonPath('total', 25)->assertJsonPath('last_page', 2)->assertJsonCount(5, 'data')->assertJsonPath('summary.confirmed_cost_centimes', 750000);
        $this->assertArrayNotHasKey('history', $response->json('data.0'));
        $this->assertArrayNotHasKey('documents', $response->json('data.0'));
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringContainsString('limit 20 offset 20', $queries);
        $this->assertStringContainsString('EXISTS (SELECT 1 FROM expense_documents', $queries);
        $this->assertStringNotContainsString('maintenance_followups', $queries);
    }

    public function test_financial_projection_tracks_work_revisions_payment_correction_refund_and_file_cancellation(): void
    {
        [$c,,,$v] = $this->agency();
        $base = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson($base.'/files', $this->maintenanceInput(['paid_centimes' => 65000, 'paid_on' => '2026-10-20', 'method' => 'cash']))->assertCreated()->json('id');
        $assertSameSources = function () use ($c, $base, $id) {
            $file = $this->getJson("$base/$id/file")->assertOk()->json('data');
            $expense = $this->getJson('/api/v1/expenses')->assertOk()->json('data.0');
            $overview = Tenant::run($c->id, fn () => app(MaintenanceOverview::class)->snapshot()['files'][0]);
            foreach (['cost_centimes', 'gross_paid_centimes', 'refunded_centimes', 'paid_centimes', 'balance_centimes', 'credit_centimes', 'cost_final', 'unknown_count'] as $key) {
                $this->assertSame($file[$key], $expense[$key], $key);
                $this->assertSame($overview[$key], $expense[$key], $key);
            }

            return $file;
        };
        $d = $assertSameSources();
        $this->postJson("$base/$id/work", [...$d['items'][0], 'amount_centimes' => 50000, 'reason' => 'Prix final revu', 'revision' => $d['revision'], 'request_key' => (string) Str::uuid()])->assertCreated();
        $d = $assertSameSources();
        $pid = $this->getJson("$base/$id/file")->json('payments.0.id');
        $this->postJson("$base/$id/payments/$pid/corrections", ['amount_centimes' => 60000, 'reason' => 'Correction du montant saisi', 'correction_revision' => 0, 'work_revision' => $d['revision'], 'expected_paid_centimes' => 65000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $d = $assertSameSources();
        $this->postJson("$base/$id/refunds", ['amount_centimes' => 60000, 'received_on' => '2026-10-21', 'method' => 'cash', 'reason' => 'Versement retourné intégralement', 'work_revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => $d['paid_centimes'], 'expected_refunded_centimes' => $d['refunded_centimes'], 'request_key' => (string) Str::uuid()])->assertCreated();
        $d = $assertSameSources();
        $this->postJson("$base/$id/cancellations", ['scope' => 'file', 'item_key' => null, 'reason' => 'Dossier saisi par erreur', 'revision' => $d['revision'], 'cancellation_revision' => $d['cancellation_revision'], 'expected_paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $assertSameSources();
    }
}
