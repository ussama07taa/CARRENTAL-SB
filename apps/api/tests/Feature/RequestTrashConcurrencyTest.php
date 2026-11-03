<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

class RequestTrashConcurrencyTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_payment_and_trash_cannot_both_commit(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        $c = Company::factory()->create();
        [$m,$client,$v] = Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $c->id]);
        $id = DB::table('reservations')->insertGetId(['company_id' => $c->id, 'customer_id' => $client->id, 'vehicle_id' => $v->id, 'actor_membership_id' => $m->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3), 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
        DB::disconnect();
        app('redis')->purge('default');
        app('redis')->purge('cache');
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            foreach (['disposal', 'payments'] as $operation) {
                $file = tempnam(sys_get_temp_dir(), 'trash-race-');
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
                        $input = $operation === 'disposal' ? ['action' => 'trash', 'reason' => 'Doublon test', 'request_key' => (string) Str::uuid()] : ['amount_centimes' => 10000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
                        $result = $this->postJson("/api/v1/reservations/$id/$operation", $input);
                        file_put_contents($file, (string) $result->status());
                    } catch (\Throwable $e) {
                        file_put_contents($file, $e->getMessage());
                    }
                    DB::disconnect();
                    exit(0);
                }$children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map('file_get_contents', $files);
            $this->assertTrue(in_array($results, [['200', '404'], ['422', '201']], true), json_encode($results));
            $trash = DB::table('reservation_events')->where('reservation_id', $id)->where('action', 'request_trashed')->count();
            $payments = DB::table('rental_payments')->where('reservation_id', $id)->count();
            $this->assertSame(1, $trash + $payments);
            $this->assertDatabaseCount('reservations', 1);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
