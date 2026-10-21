<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenancePaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_payments_cannot_exceed_the_balance(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for real concurrent PostgreSQL test.');
        }
        $company = Company::factory()->create();
        [$m,$v] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson("/api/v1/vehicles/$v->id/maintenance", ['kind' => 'repair', 'title' => 'Concurrent payments', 'performed_on' => '2026-10-01', 'cost_centimes' => 10000, 'paid_centimes' => 0, 'in_garage' => false, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        DB::disconnect();
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'maintenance-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork test worker');
                }
                if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }
                        Tenant::run($company->id, function () use ($v, $m, $id): void {
                            DB::transaction(function () use ($v, $m, $id): void {
                                $service = new VehicleMaintenance;
                                $service->lock($v->id);
                                $entry = $service->entry($v->id, $id);
                                $input = ['amount_centimes' => 7000, 'paid_on' => '2026-10-02', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
                                $service->pay($entry, $m->id, $input, $service->hash($input));
                            });
                        });
                        file_put_contents($file, 'paid');
                    } catch (ValidationException) {
                        file_put_contents($file, 'rejected');
                    } catch (\Throwable $e) {
                        file_put_contents($file, 'error: '.$e->getMessage());
                    }
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map('file_get_contents', $files);
            sort($results);
            $this->assertSame(['paid', 'rejected'], $results);
            $this->assertSame(7000, (int) DB::table('maintenance_payments')->sum('amount_centimes'));
            $this->assertDatabaseCount('maintenance_payments', 1);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_simultaneous_refunds_cannot_refund_the_same_available_funds_twice(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for real concurrent PostgreSQL test.');
        }
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $id = $this->postJson($url, ['kind' => 'repair', 'title' => 'Concurrent refunds', 'performed_on' => '2026-10-01', 'cost_centimes' => 10000, 'paid_centimes' => 10000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'in_garage' => false, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $data = $this->getJson("$url/$id/file")->assertOk()->json('data');
        DB::disconnect();
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'refund-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork test worker');
                }
                if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }
                        $response = $this->postJson("$url/$id/refunds", ['amount_centimes' => 7000, 'received_on' => '2026-10-02', 'method' => 'cash', 'reason' => 'Remboursement réellement reçu', 'work_revision' => $data['revision'], 'cancellation_revision' => 0, 'expected_paid_centimes' => 10000, 'expected_refunded_centimes' => 0, 'request_key' => (string) Str::uuid()]);
                        file_put_contents($file, (string) $response->status());
                    } catch (\Throwable $e) {
                        file_put_contents($file, 'error: '.$e->getMessage());
                    }
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map('file_get_contents', $files);
            sort($results);
            $this->assertSame(['201', '409'], $results);
            $this->assertDatabaseCount('maintenance_refunds', 1);
            $this->assertSame(7000, (int) DB::table('maintenance_refund_allocations')->sum('amount_centimes'));
            $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 3000)->assertJsonPath('data.refunded_centimes', 7000);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_two_simultaneous_cancellations_restore_a_refund_only_once(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for real concurrent PostgreSQL test.');
        }
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $url = "/api/v1/vehicles/$vehicle->id/maintenance";
        $id = $this->postJson($url, ['kind' => 'repair', 'title' => 'Concurrent refunds', 'performed_on' => '2026-10-01', 'cost_centimes' => 10000, 'paid_centimes' => 10000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'in_garage' => false, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $data = $this->getJson("$url/$id/file")->assertOk()->json('data');
        $refund = $this->postJson("$url/$id/refunds", ['amount_centimes' => 7000, 'received_on' => '2026-10-02', 'method' => 'cash', 'reason' => 'Erreur fictive', 'work_revision' => $data['revision'], 'cancellation_revision' => 0, 'expected_paid_centimes' => 10000, 'expected_refunded_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        DB::disconnect();
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'refund-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork test worker');
                }
                if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }
                        $response = $this->postJson("$url/$id/refunds/$refund/cancellations", ['reason' => 'Montant saisi par erreur', 'work_revision' => $data['revision'], 'cancellation_revision' => 0, 'expected_paid_centimes' => 3000, 'expected_refunded_centimes' => 7000, 'request_key' => (string) Str::uuid()]);
                        file_put_contents($file, (string) $response->status());
                    } catch (\Throwable $e) {
                        file_put_contents($file, 'error: '.$e->getMessage());
                    }
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map('file_get_contents', $files);
            sort($results);
            $this->assertSame(['201', '409'], $results);
            $this->assertDatabaseCount('maintenance_refunds', 1);
            $this->assertDatabaseCount('maintenance_refund_cancellations', 1);
            $this->assertSame(7000, (int) DB::table('maintenance_refund_allocations')->sum('amount_centimes'));
            $this->getJson("$url/$id/file")->assertJsonPath('data.paid_centimes', 10000)->assertJsonPath('data.refunded_centimes', 0);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
