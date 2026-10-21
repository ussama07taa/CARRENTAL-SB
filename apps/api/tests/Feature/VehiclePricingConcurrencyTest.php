<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehiclePricingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_retries_save_one_grid(): void
    {
        $this->race(true);
    }

    public function test_simultaneous_editors_cannot_silently_overwrite_each_other(): void
    {
        $this->race(false);
    }

    public function test_simultaneous_vehicle_creations_keep_both_initial_prices(): void
    {
        $this->race(false, true);
    }

    private function race(bool $sameKey, bool $creatingVehicles = false): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for concurrent PostgreSQL verification.');
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        $company = Company::factory()->create();
        [$member, $customer, $vehicle] = Tenant::run($company->id, fn (): array => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $payload = ['configuration' => ['vehicles' => [['vehicle_id' => $vehicle->id, 'daily_centimes' => 30000, 'minimum_days' => 0]], 'seasons' => []], 'expected_version' => null, 'reason' => 'Prix convenu', 'request_key' => (string) Str::uuid()];
        DB::disconnect();
        app('redis')->purge('default');
        app('redis')->purge('cache');
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'request-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork');
                }
                if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }
                        $result = $creatingVehicles ? $this->postJson('/api/v1/vehicles', ['plate' => 'PRICE-RACE-'.$i.'-'.substr($payload['request_key'], 0, 8), 'make' => 'Dacia', 'model' => 'Logan', 'creation_key' => (string) Str::uuid(), 'initial_daily_centimes' => 30000 + $i * 1000]) : $this->postJson('/api/v1/pricing', [...$payload, 'request_key' => $sameKey ? $payload['request_key'] : (string) Str::uuid()]);
                        file_put_contents($file, (string) $result->status());
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
            $this->assertSame($creatingVehicles ? ['201', '201'] : ($sameKey ? ['200', '201'] : ['201', '409']), $results);
            $this->assertDatabaseCount('vehicle_pricing_versions', $creatingVehicles ? 2 : 1);
            $this->assertDatabaseCount('vehicle_pricing_rates', $creatingVehicles ? 3 : 1);
            if ($creatingVehicles) {
                $configuration = json_decode(DB::table('vehicle_pricing_versions')->latest('id')->value('configuration'), true);
                $prices = array_column($configuration['vehicles'], 'daily_centimes');
                sort($prices);
                $this->assertSame([30000, 31000], $prices);
            }
            $this->assertDatabaseCount('reservation_events', 0);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
