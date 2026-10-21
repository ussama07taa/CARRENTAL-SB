<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleLoanTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $company = Company::factory()->create();
        [$member,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);

        return [$company, $member, $vehicle];
    }

    private function input(int $vehicle): array
    {
        return ['vehicle_id' => $vehicle, 'lender' => 'Banque fictive', 'reference' => 'TEST-001', 'tracking_from' => '2026-10-01', 'signed_on' => '2026-01-01', 'financed_centimes' => 800000, 'installments' => [['due_on' => '2026-09-05', 'amount_centimes' => 300000, 'opening_paid_centimes' => 300000], ['due_on' => '2026-10-05', 'amount_centimes' => 300000, 'opening_paid_centimes' => 100000], ['due_on' => '2026-11-05', 'amount_centimes' => 450050, 'opening_paid_centimes' => 0]], 'opening_confirmed' => true, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    private function payment(int $balance = 200000): array
    {
        return ['amount_centimes' => 50000, 'paid_on' => '2026-10-17', 'method' => 'transfer', 'expected_remaining_centimes' => $balance, 'receipt_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_historical_amounts_are_not_payments_and_partial_payments_reconcile_every_view(): void
    {
        [$company,$member,$vehicle] = $this->fixture();
        $input = $this->input($vehicle->id);
        $id = $this->postJson('/api/v1/loans', $input)->assertCreated()->json('id');
        $this->postJson('/api/v1/loans', $input)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('vehicle_loans', 1);
        $this->assertDatabaseCount('vehicle_loan_payments', 0);
        $show = $this->getJson("/api/v1/loans/$id")->assertOk()->assertJsonPath('data.total_centimes', 1050050)->assertJsonPath('data.opening_paid_centimes', 400000)->assertJsonPath('data.recorded_paid_centimes', 0)->assertJsonPath('data.remaining_centimes', 650050)->assertJsonPath('data.overdue_centimes', 200000)->assertJsonPath('installments.0.status', 'paid')->assertJsonCount(0, 'payments');
        $installment = $show->json('installments.1.id');
        $p = $this->payment();
        $url = "/api/v1/loans/$id/installments/$installment/payments";
        $pid = $this->postJson($url, $p)->assertCreated()->json('id');
        $this->postJson($url, $p)->assertCreated()->assertJsonPath('id', $pid);
        $this->assertDatabaseCount('vehicle_loan_payments', 1);
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.remaining_centimes', 600050)->assertJsonPath('data.recorded_paid_centimes', 50000)->assertJsonPath('installments.1.remaining_centimes', 150000);
        $this->getJson('/api/v1/loans?filter=overdue')->assertOk()->assertJsonPath('summary.month_centimes', 150000)->assertJsonPath('summary.overdue_centimes', 150000)->assertJsonPath('summary.remaining_centimes', 600050)->assertJsonPath('summary.recorded_paid_centimes', 50000)->assertJsonPath('summary.opening_paid_centimes', 400000);
        $this->postJson($url, [...$this->payment(150000), 'amount_centimes' => 150000])->assertCreated();
        $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.remaining_centimes', 450050)->assertJsonPath('data.overdue_centimes', 0)->assertJsonPath('data.next_due_on', '2026-11-05')->assertJsonPath('data.remaining_count', 1)->assertJsonPath('installments.1.status', 'paid');
        $this->getJson('/api/v1/loans?filter=overdue')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/loans?filter=upcoming')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('vehicle_events', ['action' => 'loan_payment_recorded', 'actor_membership_id' => $member->id]);
    }

    public function test_schedule_requires_verified_opening_and_ordered_exact_integer_amounts(): void
    {
        [,, $v] = $this->fixture();
        $i = $this->input($v->id);
        $this->postJson('/api/v1/loans', [...$i, 'opening_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('opening_confirmed');
        $this->postJson('/api/v1/loans', [...$i, 'schedule_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('schedule_confirmed');
        foreach ([0, -1, 50.25, 1000000001] as $bad) {
            $r = $i;
            $r['installments'][0]['amount_centimes'] = $bad;
            $this->postJson('/api/v1/loans', $r)->assertUnprocessable()->assertJsonValidationErrors('installments.0.amount_centimes');
        }
        $r = $i;
        $r['installments'][1]['opening_paid_centimes'] = 300001;
        $this->postJson('/api/v1/loans', $r)->assertUnprocessable()->assertJsonValidationErrors('installments.1.opening_paid_centimes');
        $r = $i;
        $r['installments'][2]['due_on'] = '2026-08-05';
        $this->postJson('/api/v1/loans', $r)->assertUnprocessable()->assertJsonValidationErrors('installments.2.due_on');
        $r = $i;
        $r['installments'][0]['due_on'] = '2025-12-05';
        $this->postJson('/api/v1/loans', $r)->assertUnprocessable()->assertJsonValidationErrors('installments.0.due_on');
        $this->postJson('/api/v1/loans', [...$i, 'tracking_from' => '2026-10-18'])->assertUnprocessable()->assertJsonValidationErrors('tracking_from');
        $this->postJson('/api/v1/loans', [...$i, 'company_id' => 123])->assertUnprocessable();
        $malformed = $i;
        $malformed['installments'] = ['unexpected' => $i['installments'][0]];
        $this->postJson('/api/v1/loans', $malformed)->assertUnprocessable()->assertJsonValidationErrors('installments');
        $this->assertDatabaseCount('vehicle_loans', 0);
    }

    public function test_duplicate_contract_and_changed_idempotency_payload_do_not_create_another_schedule(): void
    {
        [,, $v] = $this->fixture();
        $i = $this->input($v->id);
        $this->postJson('/api/v1/loans', $i)->assertCreated();
        $this->postJson('/api/v1/loans', [...$i, 'reference' => 'different'])->assertConflict();
        $this->postJson('/api/v1/loans', [...$i, 'lender' => ' BANQUE FICTIVE ', 'reference' => 'test-001', 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseCount('vehicle_loans', 1);
        $this->assertDatabaseCount('vehicle_loan_installments', 3);
    }

    public function test_stale_overpayment_unconfirmed_and_pre_tracking_payments_are_rejected(): void
    {
        [,, $v] = $this->fixture();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->json('id');
        $row = $this->getJson("/api/v1/loans/$id")->json('installments.1.id');
        $url = "/api/v1/loans/$id/installments/$row/payments";
        $p = $this->payment();
        $this->postJson($url, [...$p, 'amount_centimes' => 200001])->assertUnprocessable()->assertJsonValidationErrors('amount_centimes');
        $this->postJson($url, [...$p, 'expected_remaining_centimes' => 300000])->assertConflict();
        $this->postJson($url, [...$p, 'paid_on' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->postJson($url, [...$p, 'paid_on' => '2026-10-18'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->postJson($url, [...$p, 'receipt_confirmed' => false])->assertUnprocessable();
        $this->postJson($url, [...$p, 'amount_centimes' => 100.5])->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_loan_payments', 0);
        $this->postJson($url, $p)->assertCreated();
        $this->postJson($url, [...$p, 'amount_centimes' => 10000])->assertConflict();
        $this->postJson($url, [...$p, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('vehicle_loan_payments', 1);
    }

    public function test_private_contracts_isolation_and_responsible_only_access(): void
    {
        Storage::fake('vehicle_documents');
        [$company,$m,$v] = $this->fixture();
        $i = $this->input($v->id);
        $i['file'] = UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $id = $this->postJson('/api/v1/loans', $i)->assertCreated()->json('id');
        $d = $this->getJson("/api/v1/loans/$id")->assertOk();
        $doc = $d->json('documents.0.id');
        $row = $d->json('installments.1.id');
        $this->assertArrayNotHasKey('path', $d->json('documents.0'));
        $this->get("/api/v1/loans/$id/documents/$doc/content")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/loans/$id/documents", ['file' => UploadedFile::fake()->createWithContent('bad.pdf', 'not a PDF'), 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $other = Company::factory()->create();
        $otherMember = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherMember->user)->withSession(['company_id' => $other->id]);
        $this->getJson('/api/v1/loans')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.remaining_centimes', 0);
        $this->getJson("/api/v1/loans/$id")->assertNotFound();
        $this->getJson("/api/v1/loans/$id/documents/$doc/content")->assertNotFound();
        $this->postJson("/api/v1/loans/$id/installments/$row/payments", $this->payment())->assertNotFound();
        $this->postJson('/api/v1/loans', $this->input($v->id))->assertNotFound();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->withSession(['company_id' => $company->id]);
        $this->getJson('/api/v1/loans')->assertForbidden();
        $this->getJson("/api/v1/loans/$id")->assertForbidden();
        $this->postJson('/api/v1/loans', $this->input($v->id))->assertForbidden();
        $this->postJson("/api/v1/loans/$id/installments/$row/payments", $this->payment())->assertForbidden();
        $this->getJson("/api/v1/loans/$id/documents/$doc/content")->assertForbidden();
        $this->actingAs($m->user)->withSession(['company_id' => null]);
        $this->getJson('/api/v1/loans')->assertForbidden();
    }

    public function test_storage_failure_rolls_back_contract_schedule_and_audit(): void
    {
        [,, $v] = $this->fixture();
        $i = $this->input($v->id);
        $i['file'] = UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\n%%EOF");
        Storage::shouldReceive('disk')->with('vehicle_documents')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('delete')->once()->andReturn(true);
        $this->postJson('/api/v1/loans', $i)->assertStatus(500);
        $this->assertDatabaseCount('vehicle_loans', 0);
        $this->assertDatabaseCount('vehicle_loan_installments', 0);
        $this->assertDatabaseMissing('vehicle_events', ['action' => 'loan_created']);
    }

    public function test_immutable_records_and_composite_foreign_keys_are_enforced_by_postgresql(): void
    {
        [$company,, $v] = $this->fixture();
        $id = $this->postJson('/api/v1/loans', $this->input($v->id))->assertCreated()->json('id');
        $rows = $this->getJson("/api/v1/loans/$id")->json('installments');
        $this->postJson("/api/v1/loans/$id/installments/{$rows[1]['id']}/payments", $this->payment())->assertCreated();
        foreach (['vehicle_loans', 'vehicle_loan_installments', 'vehicle_loan_payments'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $rejected = false;
                try {
                    DB::transaction(function () use ($table, $operation) {
                        $q = DB::table($table);
                        $operation === 'delete' ? $q->delete() : $q->update(['company_id' => 999]);
                    });
                } catch (QueryException) {
                    $rejected = true;
                }$this->assertTrue($rejected, "$table $operation must fail");
            }
        }
        $another = $this->input($v->id);
        $another['reference'] = 'SECOND';
        $id2 = $this->postJson('/api/v1/loans', $another)->assertCreated()->json('id');
        $payment = (array) DB::table('vehicle_loan_payments')->first();
        unset($payment['id']);
        $payment['loan_id'] = $id2;
        $payment['request_key'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('vehicle_loan_payments')->insert($payment));
    }
}
