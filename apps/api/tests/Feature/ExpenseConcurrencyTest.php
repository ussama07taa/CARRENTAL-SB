<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseMigrations as DatabaseMigrations;
use Tests\TestCase;

class ExpenseConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_payments_cannot_use_the_same_balance(): void
    {
        $this->race(false);
    }

    public function test_retrying_the_same_payment_at_the_same_time_records_it_once(): void
    {
        $this->race(true);
    }

    private function race(bool $sameKey): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required.');
        }
        $company = Company::factory()->create();
        $member = Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $today = now('Africa/Casablanca')->toDateString();
        $id = $this->postJson('/api/v1/expenses', ['title' => 'Facture simultanée TEST', 'supplier' => 'Station TEST', 'category' => 'fuel', 'incurred_on' => $today, 'amount_centimes' => 10000, 'allocations' => [['vehicle_id' => null, 'amount_centimes' => 10000]], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $revision = $this->getJson('/api/v1/expenses/'.$id)->json('data.revision');
        $key = (string) Str::uuid();
        $files = [];
        $children = [];
        $start = microtime(true) + .3;
        DB::disconnect();
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'expense-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork');
                }if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }$response = $this->postJson("/api/v1/expenses/$id/movements", ['kind' => 'payment', 'amount_centimes' => 7000, 'method' => 'cash', 'occurred_on' => $today, 'reason' => 'Paiement TEST', 'confirmed' => true, 'expected_revision' => $revision, 'request_key' => $sameKey ? $key : (string) Str::uuid()]);
                        file_put_contents($file, (string) $response->status());
                    } catch (\Throwable $e) {
                        file_put_contents($file, 'error: '.$e->getMessage());
                    }DB::disconnect();
                    exit(0);
                }$children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }$results = array_map('file_get_contents', $files);
            sort($results);
            $this->assertSame($sameKey ? ['201', '201'] : ['201', '409'], $results);
            $this->assertDatabaseCount('expense_movements', 1);
            $this->getJson('/api/v1/expenses/'.$id)->assertOk()->assertJsonPath('data.paid_centimes', 7000)->assertJsonPath('data.balance_centimes', 3000);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
