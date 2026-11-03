<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Finance\ReportBalances;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestTrashTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 3)->startOfDay());
        $company = Company::factory()->create();
        [$m,$c,$v] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $company->id]);
        $id = DB::table('reservations')->insertGetId(['company_id' => $company->id, 'customer_id' => $c->id, 'vehicle_id' => $v->id, 'actor_membership_id' => $m->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3), 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);

        return [$company, $m, $id];
    }

    private function input(string $action): array
    {
        return ['action' => $action, 'reason' => 'Doublon de saisie', 'request_key' => (string) Str::uuid()];
    }

    public function test_trash_restore_idempotency_scope_and_money_remain_intact(): void
    {
        [$company,$m,$id] = $this->fixture();
        $url = "/api/v1/reservations/$id/disposal";
        $other = (array) DB::table('reservations')->where('id', $id)->first();
        unset($other['id']);
        $other['request_key'] = (string) Str::uuid();
        $paid = DB::table('reservations')->insertGetId($other);
        $this->postJson("/api/v1/reservations/$paid/payments", ['amount_centimes' => 25000, 'method' => 'especes', 'received_on' => '2026-11-03', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $before = $this->getJson('/api/v1/cash')->assertOk()->json('cash');
        $balances = Tenant::run($company->id, fn () => app(ReportBalances::class)->index(null, '2026-11-01', '2026-11-30'));
        $this->getJson($url)->assertOk()->assertJsonPath('can_trash', true);
        $input = $this->input('trash');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('replayed', false);
        $this->postJson($url, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('reservations', 2);
        $this->getJson('/api/v1/reservations')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/reservations?trash=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/reservations/'.$id)->assertNotFound();
        $this->postJson('/api/v1/reservations/'.$id.'/hold', ['agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/api/v1/reservations/'.$id.'/payments', ['amount_centimes' => 10000, 'method' => 'especes', 'received_on' => '2026-11-03', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame($before, $this->getJson('/api/v1/cash')->assertOk()->json('cash'));
        $this->assertSame($balances, Tenant::run($company->id, fn () => app(ReportBalances::class)->index(null, '2026-11-01', '2026-11-30')));
        $restore = $this->input('restore');
        $this->postJson($url, $restore)->assertOk();
        $this->postJson($url, $restore)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/reservations')->assertOk()->assertJsonPath('data.0.status', 'demande');
        $this->getJson('/api/v1/reservations?trash=1')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(2, DB::table('reservation_events')->where('reservation_id', $id)->count());
        $this->assertDatabaseCount('rental_payments', 1);
    }

    public function test_payment_even_pending_and_documents_prevent_trash(): void
    {
        [$company,$m,$id] = $this->fixture();
        $url = "/api/v1/reservations/$id/disposal";
        $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 10000, 'method' => 'virement', 'received_on' => '2026-11-03', 'reference' => 'TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson($url)->assertJsonPath('can_trash', false)->assertJsonPath('can_cancel_quick', false);
        $this->postJson($url, $this->input('trash'))->assertUnprocessable();
        $this->assertDatabaseCount('rental_payments', 1);
        [$company,$m,$id] = $this->fixture();
        DB::table('reservation_departure_entries')->insert(['company_id' => $company->id, 'reservation_id' => $id, 'actor_membership_id' => $m->id, 'action' => 'documents_checked', 'reason' => 'Pièces vérifiées', 'data' => '{}', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'created_at' => now()]);
        $this->postJson("/api/v1/reservations/$id/disposal", $this->input('trash'))->assertUnprocessable();
    }

    public function test_quick_cancellation_uses_existing_ledger_and_permissions_are_preserved(): void
    {
        [$company,$m,$id] = $this->fixture();
        $url = "/api/v1/reservations/$id/disposal";
        $this->postJson($url, $this->input('cancel_quick'))->assertOk();
        $this->getJson('/api/v1/reservations?status=annulee')->assertJsonPath('total', 1);
        $this->assertDatabaseCount('rental_cancellations', 1);
        $this->assertDatabaseCount('rental_payments', 0);
        $ledger = DB::table('rental_settlement_entries')->where('reservation_id', $id)->get()->toJson();
        $cash = $this->getJson('/api/v1/cash')->assertOk()->json('cash');
        $this->getJson($url)->assertJsonPath('can_trash', true)->assertJsonPath('can_cancel_quick', false);
        $this->postJson($url, $this->input('trash'))->assertOk();
        $this->getJson($url)->assertJsonPath('restore_status', 'annulee');
        $this->postJson($url, $this->input('restore'))->assertOk();
        $this->getJson('/api/v1/reservations?status=annulee')->assertJsonPath('total', 1);
        $this->assertSame($ledger, DB::table('rental_settlement_entries')->where('reservation_id', $id)->get()->toJson());
        $this->assertSame($cash, $this->getJson('/api/v1/cash')->assertOk()->json('cash'));
        $this->assertDatabaseCount('rental_cancellations', 1);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user)->getJson($url)->assertForbidden();
        $this->postJson($url, $this->input('trash'))->assertForbidden();
        $this->getJson('/api/v1/reservations?trash=1')->assertForbidden();
        $other = Company::factory()->create();
        $manager = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($manager->user)->withSession(['company_id' => $other->id]);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, $this->input('restore'))->assertNotFound();
    }

    public function test_website_request_is_cancelled_not_trashed_and_audit_failure_rolls_back(): void
    {
        [$company,$m,$id] = $this->fixture();
        $url = "/api/v1/reservations/$id/disposal";
        DB::table('reservations')->where('id', $id)->update(['source' => 'website', 'actor_membership_id' => null, 'website_request' => '{}']);
        $this->getJson($url)->assertJsonPath('can_trash', false)->assertJsonPath('can_cancel_quick', true);
        $this->postJson($url, $this->input('trash'))->assertUnprocessable();
        [$company,$m,$id] = $this->fixture();
        $url = "/api/v1/reservations/$id/disposal";
        DB::unprepared("CREATE FUNCTION test_trash_audit_failure() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'test'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER fail_trash_event BEFORE INSERT ON reservation_events FOR EACH STATEMENT EXECUTE FUNCTION test_trash_audit_failure();");
        $this->postJson($url,$this->input('trash'))->assertServerError();
        $this->assertDatabaseHas('reservations',['id' => $id, 'status' => 'demande']);
        DB::unprepared('DROP TRIGGER fail_trash_event ON reservation_events; DROP FUNCTION test_trash_audit_failure();');
    }
}
