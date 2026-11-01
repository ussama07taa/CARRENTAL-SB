<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Finance\Expenses;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

class FinancialSnapshotConcurrencyTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public static function pages(): array
    {
        return [
            'expenses' => ['/api/v1/expenses', 'AS unpaid', 'summary.paid_centimes', 10000, 'total', 1],
            'reports' => ['/api/v1/reports', 'AS net_centimes,COUNT(*) AS count', 'summary.outgoing_centimes', 10000, 'summary.count', 1],
            'cash' => ['/api/v1/cash', 'AS digest', 'cash.expected_centimes', 90000, 'channels.cash.outgoing', 10000],
        ];
    }

    #[DataProvider('pages')]
    public function test_one_response_keeps_one_snapshot_when_another_connection_records_a_payment(string $url, string $needle, string $amountPath, int $amount, string $countPath, int $count): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-24T12:00Z'));
        $company = Company::factory()->create();
        $manager = Tenant::run($company->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($manager->user)->withSession(['company_id' => $company->id]);
        $this->postJson('/api/v1/cash/entries', ['kind' => 'opening', 'amount_centimes' => 100000, 'occurred_on' => '2026-10-24', 'reason' => 'Recette isolée', 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $payload = fn (int $v) => ['title' => 'Dépense fictive', 'category' => 'wash', 'supplier' => 'Station', 'invoice_reference' => (string) Str::uuid(), 'incurred_on' => '2026-10-24', 'amount_centimes' => $v, 'allocations' => [['vehicle_id' => null, 'amount_centimes' => $v]], 'paid_centimes' => $v, 'paid_on' => '2026-10-24', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/expenses', $payload(10000))->assertCreated();
        $original = DB::getDefaultConnection();
        config(['database.connections.audit_writer' => config('database.connections.'.$original)]);
        $fired = false;
        DB::listen(function ($query) use (&$fired, $needle, $original, $manager, $payload) {
            if ($fired || $query->connectionName !== $original || ! str_contains($query->sql, $needle)) {
                return;
            }
            $fired = true;
            DB::setDefaultConnection('audit_writer');
            try {
                app(Expenses::class)->save(null, $manager->id, $payload(20000));
            } finally {
                DB::setDefaultConnection($original);
            }
        });
        try {
            $response = $this->getJson($url)->assertOk();
            $this->assertTrue($fired, 'Concurrent committed write must have happened during the read');
            $this->assertDatabaseCount('expenses', 2);
            $response->assertJsonPath($amountPath, $amount)->assertJsonPath($countPath, $count);
            if ($url === '/api/v1/reports') {
                $this->assertSame(10000, array_sum(array_column($response->json('daily'), 'outgoing_centimes')));
            }
        } finally {
            DB::purge('audit_writer');
        }
    }
}
