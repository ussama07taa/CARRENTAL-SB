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

class RentalPaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_confirmation_keeps_one_allocation_and_both_receipts(): void
    {
        $this->race(false);
    }

    public function test_repeated_confirmation_only_creates_one_event(): void
    {
        $this->race(true);
    }

    public function test_simultaneous_payment_retries_create_one_receipt(): void
    {
        $this->race(true, true);
    }

    private function race(bool $sameKey, bool $paymentRace = false): void
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
        $quotes = [];
        foreach ([$one, $two] as $id) {
            $quotes[$id] = $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 270000, 'reason' => 'Prix convenu', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
            if (! $paymentRace) {
                $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 270000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
            }
        }
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
                        $result = $paymentRace ? $this->postJson("/api/v1/reservations/$target/payments", ['amount_centimes' => 270000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => $payload['request_key']]) : $this->postJson("/api/v1/reservations/$target/confirm", ['quote_id' => $quotes[$target], 'agreement_confirmed' => true, 'request_key' => $sameKey ? $payload['request_key'] : (string) Str::uuid()]);
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
            $this->assertSame($paymentRace ? 0 : 1, DB::table('reservations')->where('status', 'confirmee')->count());
            $this->assertDatabaseCount('reservation_confirmations', $paymentRace ? 0 : 1);
            $this->assertDatabaseCount('rental_payments', $paymentRace ? 1 : 2);
            $this->assertSame($paymentRace ? 0 : 1, DB::table('reservation_events')->where('action', 'reservation_confirmed')->count());
        } finally {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
