<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Reservations\RequestAvailability;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceOperationsAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $company = Company::factory()->create();

        return Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create(), Vehicle::factory()->create()]);
    }

    private function login(CompanyMembership $member): void
    {
        $this->actingAs($member->user)->withSession(['company_id' => $member->company_id]);
    }

    private function work(array $changes = []): array
    {
        return array_replace(['kind' => 'brakes', 'title' => 'Freins de démonstration', 'status' => 'pending', 'cost_status' => 'confirmed', 'amount_centimes' => 65000, 'completed_on' => null, 'kilometers' => null, 'next_on' => null, 'next_km' => null], $changes);
    }

    private function create(Vehicle $vehicle, array $changes = []): string
    {
        $id = $this->postJson("/api/v1/vehicles/$vehicle->id/maintenance/files", array_replace(['mode' => 'garage', 'performed_on' => '2026-10-01', 'items' => [$this->work()], 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'request_key' => (string) Str::uuid()], $changes))->assertCreated()->json('id');

        return "/api/v1/vehicles/$vehicle->id/maintenance/$id";
    }

    private function returnInput(bool $ready = false): array
    {
        return ['returned_on' => '2026-10-02', 'note' => 'Retour réel, contrôle encore nécessaire.', 'ready' => $ready, 'request_key' => (string) Str::uuid()];
    }

    private function finish(string $url): void
    {
        $entry = $this->getJson("$url/file")->assertOk()->json('data');
        $work = $this->work(['status' => 'done', 'completed_on' => '2026-10-03']);
        unset($work['cost_status'], $work['amount_centimes']);
        $this->postJson("$url/work", [...$work, 'item_key' => $entry['items'][0]['item_key'], 'revision' => $entry['revision'], 'reason' => 'Travail effectué et contrôlé.', 'request_key' => (string) Str::uuid()])->assertCreated();
    }

    private function readiness(string $url, ?int $returnId = null): array
    {
        return ['followup_return_id' => $returnId, 'revision' => $this->getJson("$url/file")->json('data.revision'), 'checked_on' => '2026-10-04', 'note' => 'Contrôle réel effectué, voiture prête.', 'ready' => true, 'request_key' => (string) Str::uuid()];
    }

    private function assertNoFinance(array $body): void
    {
        array_walk_recursive($body, function ($value, $key): void {
            $this->assertFalse(str_contains((string) $key, 'centimes'), "Financial field exposed: $key");
            $this->assertNotContains($key, ['cost_status', 'cost_final', 'has_file', 'mime', 'payment_due_on']);
        });
        foreach (['payments', 'refunds', 'attachments'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
    }

    public function test_employee_reads_operations_without_money_and_cannot_open_financial_endpoints(): void
    {
        [$manager, $employee, $vehicle] = $this->agency();
        $this->login($manager);
        $url = $this->create($vehicle);
        $this->login($employee);
        foreach (["$url/file", "/api/v1/vehicles/$vehicle->id/maintenance", '/api/v1/maintenance/overview?tab=files', '/api/v1/maintenance/overview?tab=all'] as $path) {
            $body = $this->getJson($path)->assertOk()->assertJsonPath('can_manage_finance', false)->json();
            $this->assertNoFinance($body);
        }
        $this->getJson('/api/v1/maintenance/overview?tab=payments')->assertForbidden();
        $this->getJson('/api/v1/maintenance/overview?tab=credits')->assertForbidden();
        $this->getJson("$url/content")->assertForbidden();
        $this->getJson("$url/invoices/999")->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->postJson("$url/payments", ['amount_centimes' => 100, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->login($manager);
        $this->getJson("$url/file")->assertJsonPath('can_manage_finance', true)->assertJsonPath('data.cost_centimes', 65000)->assertJsonPath('data.paid_centimes', 20000);
    }

    public function test_employee_finishes_existing_work_without_changing_price_or_payments(): void
    {
        [$manager, $employee, $vehicle] = $this->agency();
        $this->login($manager);
        $url = $this->create($vehicle);
        $initial = $this->getJson("$url/file")->json('data');
        $this->login($employee);
        $this->postJson("$url/work", [...$this->work(['amount_centimes' => 1]), 'item_key' => $initial['items'][0]['item_key'], 'revision' => $initial['revision'], 'reason' => 'Interdit', 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['cost_status', 'amount_centimes']);
        $this->finish($url);
        $this->login($manager);
        $this->getJson("$url/file")->assertJsonPath('data.items.0.status', 'done')->assertJsonPath('data.items.0.amount_centimes', 65000)->assertJsonPath('data.items.0.cost_status', 'confirmed')->assertJsonPath('data.balance_centimes', 45000)->assertJsonCount(2, 'history');
        $this->assertDatabaseCount('maintenance_payments', 1);
    }

    public function test_employee_creates_file_without_financial_input_and_manager_can_price_it_later(): void
    {
        [$manager, $employee, $vehicle] = $this->agency();
        $this->login($employee);
        $work = $this->work();
        unset($work['cost_status'], $work['amount_centimes']);
        $input = ['mode' => 'garage', 'performed_on' => '2026-10-01', 'items' => [$work], 'request_key' => (string) Str::uuid()];
        $base = "/api/v1/vehicles/$vehicle->id/maintenance";
        $this->postJson("$base/files", [...$input, 'paid_centimes' => 100])->assertUnprocessable()->assertJsonValidationErrors('paid_centimes');
        $id = $this->postJson("$base/files", $input)->assertCreated()->json('id');
        $this->postJson("$base/files", $input)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 0);
        $this->getJson('/api/v1/maintenance/overview?tab=pricing')->assertForbidden();
        $this->login($manager);
        $this->getJson('/api/v1/maintenance/overview?tab=pricing')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'price');
        $entry = $this->getJson("$base/$id/file")->assertJsonPath('data.items.0.cost_status', 'unknown')->assertJsonPath('data.items.0.amount_centimes', null)->json('data');
        $this->postJson("$base/$id/work", [...$this->work(), 'item_key' => $entry['items'][0]['item_key'], 'revision' => $entry['revision'], 'reason' => 'Devis confirmé par le responsable', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("$base/$id/file")->assertJsonPath('data.cost_centimes', 65000);
    }

    public function test_return_without_readiness_is_recorded_but_blocks_availability_until_explicit_check(): void
    {
        [$manager, $employee, $vehicle] = $this->agency();
        $this->login($manager);
        $url = $this->create($vehicle);
        $this->login($employee);
        $this->postJson("$url/return", $this->returnInput(true))->assertUnprocessable();
        $input = $this->returnInput();
        $this->postJson("$url/return", $input)->assertCreated();
        $this->postJson("$url/return", $input)->assertOk();
        $this->getJson("$url/file")->assertJsonPath('data.garage_open', false)->assertJsonPath('data.returned.ready', false)->assertJsonCount(1, 'data.readiness_pending');
        Tenant::run($manager->company_id, function () use ($vehicle): void {
            $a = app(RequestAvailability::class);
            $this->assertNotContains($vehicle->id, $a->garageVehicles());
            $this->assertContains($vehicle->id, $a->blockedVehicles());
            $this->assertStringContainsString('disponibilité', $a->blockingReasons('2026-11-01T10:00:00Z', '2026-11-02T10:00:00Z')[$vehicle->id]);
        });
        $this->getJson('/api/v1/maintenance/overview?tab=readiness')->assertJsonPath('total', 1)->assertJsonPath('data.0.state', 'unready');
        $this->getJson('/api/v1/reservations/planning?from=2026-10-01&days=7')->assertOk()->assertJsonPath('data.0.in_garage', false)->assertJsonPath('data.0.return_pending', true);
        $check = $this->readiness($url);
        $this->postJson("$url/readiness", $check)->assertUnprocessable()->assertJsonValidationErrors('ready');
        $this->finish($url);
        $this->postJson("$url/readiness", $check)->assertConflict();
        $check = $this->readiness($url);
        $this->postJson("$url/readiness", [...$check, 'checked_on' => '2026-10-02'])->assertUnprocessable();
        $this->postJson("$url/readiness", [...$check, 'ready' => false])->assertUnprocessable();
        $this->postJson("$url/readiness", [...$check, 'checked_on' => '2026-10-01'])->assertUnprocessable();
        $this->postJson("$url/readiness", $check)->assertCreated();
        $this->postJson("$url/readiness", $check)->assertCreated();
        $this->getJson("$url/file")->assertJsonCount(0, 'data.readiness_pending');
        Tenant::run($manager->company_id, fn () => $this->assertNotContains($vehicle->id, app(RequestAvailability::class)->blockedVehicles()));
        $this->assertDatabaseCount('maintenance_returns', 1);
        $this->assertDatabaseCount('maintenance_readiness', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'maintenance_readiness_checked', 'actor_membership_id' => $employee->id]);
        $this->login($manager);
        $this->getJson("$url/file")->assertJsonPath('data.balance_centimes', 45000);
    }

    public function test_two_pending_returns_remain_independent_and_tenant_isolation_is_preserved(): void
    {
        [$manager, $employee, $vehicle] = $this->agency();
        $this->login($manager);
        $a = $this->create($vehicle, ['items' => [$this->work(['status' => 'done', 'completed_on' => '2026-10-01'])]]);
        $b = $this->create($vehicle, ['items' => [$this->work(['status' => 'done', 'completed_on' => '2026-10-01'])]]);
        $this->login($employee);
        $this->postJson("$a/return", $this->returnInput())->assertCreated();
        $this->postJson("$b/return", $this->returnInput())->assertCreated();
        $check = $this->readiness($a);
        $this->postJson("$a/readiness", $check)->assertCreated();
        Tenant::run($manager->company_id, fn () => $this->assertContains($vehicle->id, app(RequestAvailability::class)->blockedVehicles()));
        [$other] = $this->agency();
        $this->login($other);
        $this->getJson("$b/file")->assertNotFound();
        $this->postJson("$b/readiness", $check)->assertNotFound();
        $this->login($employee);
        $this->postJson("$b/readiness", $this->readiness($b))->assertCreated();
        Tenant::run($manager->company_id, fn () => $this->assertNotContains($vehicle->id, app(RequestAvailability::class)->blockedVehicles()));
    }

    public function test_followup_can_return_unfinished_then_finish_and_validate_without_duplicate_cost(): void
    {
        [$manager, $employee, $vehicle] = $this->agency();
        $this->login($manager);
        $url = $this->create($vehicle, ['items' => [$this->work(['status' => 'deferred', 'reason' => 'Pièce à commander'])]]);
        $this->postJson("$url/return", $this->returnInput(true))->assertCreated();
        $this->login($employee);
        $send = function (string $action, string $date, array $extra = []) use ($url): int {
            $e = $this->getJson("$url/file")->json('data');
            $key = $e['items'][0]['item_key'];

            return $this->postJson("$url/work/$key/follow-up", [...$extra, 'action' => $action, 'occurred_on' => $date, 'note' => 'Suivi réel du travail de démonstration.', 'revision' => $e['revision'], 'tracking_revision' => $e['followups'][$key]['tracking_revision'], 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        };
        $send('start', '2026-10-03');
        $returned = $send('return', '2026-10-03', ['ready' => false]);
        $this->getJson("$url/file")->assertJsonCount(1, 'data.readiness_pending');
        $send('complete', '2026-10-04');
        $this->postJson("$url/readiness", $this->readiness($url, $returned + 999))->assertConflict();
        $this->postJson("$url/readiness", $this->readiness($url, $returned))->assertCreated();
        $this->login($manager);
        $this->getJson("$url/file")->assertJsonPath('data.cost_centimes', 65000)->assertJsonPath('data.paid_centimes', 20000)->assertJsonCount(0, 'data.readiness_pending');
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 1);
    }

    public function test_database_rejects_known_price_with_null_amount_and_history_mutations(): void
    {
        [$manager, , $vehicle] = $this->agency();
        $this->login($manager);
        $url = $this->create($vehicle, ['items' => [$this->work(['status' => 'done', 'completed_on' => '2026-10-01'])]]);
        $this->postJson("$url/return", $this->returnInput())->assertCreated();
        $this->postJson("$url/readiness", $this->readiness($url))->assertCreated();
        foreach (['estimate', 'confirmed'] as $status) {
            $row = (array) DB::table('maintenance_work_items')->first();
            unset($row['id']);
            $row = [...$row, 'item_key' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), 'cost_status' => $status, 'amount_centimes' => null];
            $this->rejects(fn () => DB::table('maintenance_work_items')->insert($row), '23514');
        }
        foreach (['maintenance_followups', 'maintenance_readiness'] as $table) {
            $this->rejects(fn () => DB::table($table)->update(['note' => 'Écrasement']), 'P0001');
            $this->rejects(fn () => DB::table($table)->delete(), 'P0001');
            $this->rejects(fn () => DB::statement("TRUNCATE TABLE $table CASCADE"), 'P0001');
        }
        $this->assertDatabaseCount('maintenance_readiness', 1);
    }

    private function rejects(callable $write, string $state): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must reject this mutation.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0]);
        }
    }
}
