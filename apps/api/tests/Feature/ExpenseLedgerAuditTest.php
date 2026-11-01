<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseLedgerAuditTest extends TestCase
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

    public function test_independent_centime_ledger_matches_details_pages_cash_and_reports_after_mutations(): void
    {
        [$company,$owner,,$vehicle] = $this->agency();
        $this->assertSame('bensebih_test', DB::connection()->getDatabaseName());
        $this->postJson('/api/v1/cash/entries', ['kind' => 'opening', 'amount_centimes' => 10000000, 'occurred_on' => '2026-09-01', 'reason' => 'Ouverture fictive', 'confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $expected = [];
        $cashNet = 0;
        $grossTotal = 0;
        $refundTotal = 0;
        for ($i = 0; $i < 25; $i++) {
            $cost = 10101 + $i * 137;
            $paid = intdiv($cost, 2);
            $method = ['cash', 'transfer', 'card'][$i % 3];
            $input = $this->input(['invoice_reference' => 'AUDIT-'.$i, 'incurred_on' => '2026-09-20', 'amount_centimes' => $cost, 'allocations' => [['vehicle_id' => $vehicle->id, 'amount_centimes' => $cost]], 'paid_centimes' => $paid, 'paid_on' => '2026-10-20', 'method' => $method]);
            $id = $this->postJson('/api/v1/expenses', $input)->assertCreated()->json('id');
            $this->postJson('/api/v1/expenses', $input)->assertCreated()->assertJsonPath('id', $id);
            // Correct the original receipt, not an additional payment.
            $first = $this->current($id)['movements'][0];
            $paid -= 17;
            $fix = $this->movement($id, ['movement_key' => $first['movement_key'], 'amount_centimes' => $paid, 'method' => $method, 'occurred_on' => '2026-10-20']);
            $this->postJson("/api/v1/expenses/$id/movements", $fix)->assertCreated();
            $this->postJson("/api/v1/expenses/$id/movements", $fix)->assertCreated();
            $refunded = 101 + $i;
            $refund = $this->movement($id, ['kind' => 'refund', 'amount_centimes' => $refunded, 'method' => $method, 'occurred_on' => '2026-10-21']);
            $this->postJson("/api/v1/expenses/$id/movements", $refund)->assertCreated();
            $this->postJson("/api/v1/expenses/$id/movements", $refund)->assertCreated();
            if ($i % 5 === 0) {
                $cancel = ['reason' => 'Facture fictive annulée', 'confirmed' => true, 'expected_revision' => $this->current($id)['revision'], 'request_key' => (string) Str::uuid()];
                $this->postJson("/api/v1/expenses/$id/cancel", $cancel)->assertCreated();
                $cost = 0;
            }
            $net = $paid - $refunded;
            $e = ['cost_centimes' => $cost, 'paid_centimes' => $net, 'gross_paid_centimes' => $paid, 'refunded_centimes' => $refunded, 'balance_centimes' => max(0, $cost - $net), 'credit_centimes' => max(0, $net - $cost)];
            $detail = $this->current($id);
            foreach ($e as $k => $v) {
                $this->assertSame($v, $detail[$k], "expense $id $k");
            }
            $expected[$id] = $e;
            $grossTotal += $paid;
            $refundTotal += $refunded;
            if ($method === 'cash') {
                $cashNet -= $net;
            }
        }
        $page = $this->getJson('/api/v1/expenses')->assertOk()->assertJsonPath('total', 25)->assertJsonPath('last_page', 2)->json();
        foreach (['paid_centimes', 'gross_paid_centimes', 'refunded_centimes', 'balance_centimes', 'credit_centimes'] as $k) {
            $this->assertSame(array_sum(array_column($expected, $k)), $page['summary'][$k], $k);
        }
        $this->assertSame(array_sum(array_column($expected, 'cost_centimes')), $page['summary']['confirmed_cost_centimes']);
        $all = [...$page['data'], ...$this->getJson('/api/v1/expenses?page=2')->assertOk()->json('data')];
        $this->assertCount(25, array_unique(array_column($all, 'id')));
        foreach ($all as $row) {
            foreach ($expected[$row['id']] as $k => $v) {
                $this->assertSame($v, $row[$k], "list $k");
            }
        }
        $this->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-24')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/cash?from=2026-10-01&to=2026-10-24')->assertOk()->assertJsonPath('cash.expected_centimes', 10000000 + $cashNet)->assertJsonPath('channels.cash.net', $cashNet);
        $report = $this->getJson('/api/v1/reports?month=2026-10')->assertOk()->assertJsonPath('summary.outgoing_centimes', $grossTotal)->assertJsonPath('summary.incoming_centimes', $refundTotal)->assertJsonPath('summary.net_centimes', $refundTotal - $grossTotal)->assertJsonPath('summary.count', 50)->json();
        $this->assertSame($grossTotal, array_sum(array_column($report['daily'], 'outgoing_centimes')));
        $this->assertSame($refundTotal, array_sum(array_column($report['daily'], 'incoming_centimes')));
        $this->getJson('/api/v1/reports?month=2026-09')->assertOk()->assertJsonPath('summary.count',0);
        $this->assertDatabaseCount('expenses',25);
        $this->assertDatabaseCount('expense_movements',75);
    }
}
