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

class TemporaryReservationHoldConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_repeated_simultaneous_hold_is_idempotent(): void
    {
        $this->race(true);
    }

    public function test_concurrent_holds_only_allow_one_blocking_reservation(): void
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
        $one = $this->postJson('/api/v1/reservations', $payload)->assertCreated()->json('data.id');
        $two = $this->postJson('/api/v1/reservations', [...$payload, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
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
                        $target = $sameKey || $i === 0 ? $one : $two;
                        $result = $this->postJson("/api/v1/reservations/$target/hold", ['agreement_confirmed' => true, 'request_key' => $sameKey ? $payload['request_key'] : (string) Str::uuid()]);
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
            $this->assertSame($sameKey ? ['200', '201'] : ['201', '409'], $results);
            $this->assertDatabaseCount('reservations', 2);
            $this->assertSame(1, DB::table('reservations')->where('status', 'temporaire')->count());
            $this->assertDatabaseCount('reservation_hold_requests', 1);
            $this->assertDatabaseCount('reservation_events', 3);
        } finally {
            $this->travel(3)->hours();
            $this->artisan('reservations:expire-holds')->assertSuccessful();
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
