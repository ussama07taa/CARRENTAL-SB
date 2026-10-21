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

class ReservationRequestConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_identical_simultaneous_submissions_create_one_request_and_one_event(): void
    {
        $this->race(true);
    }

    public function test_two_separate_requests_for_the_same_dates_do_not_block_each_other(): void
    {
        $this->race(false);
    }

    private function race(bool $sameKey): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for concurrent PostgreSQL verification.');
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        $company = Company::factory()->create();
        [$member, $customer, $vehicle] = Tenant::run($company->id, fn (): array => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $payload = ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => now('Africa/Casablanca')->addDay()->format('Y-m-d').'T10:00', 'ends_local' => now('Africa/Casablanca')->addDays(4)->format('Y-m-d').'T10:00', 'request_key' => (string) Str::uuid()];
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
                        $result = $this->postJson('/api/v1/reservations', [...$payload, 'request_key' => $sameKey ? $payload['request_key'] : (string) Str::uuid()]);
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
            $this->assertSame($sameKey ? ['200', '201'] : ['201', '201'], $results);
            $this->assertDatabaseCount('reservations', $sameKey ? 1 : 2);
            $this->assertDatabaseCount('reservation_events', $sameKey ? 1 : 2);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
