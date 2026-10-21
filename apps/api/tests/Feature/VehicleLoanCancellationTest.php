<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleLoans;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleLoanCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $opening = 0, int $paid = 0): array
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $company = Company::factory()->create();
        [$member, $vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $create = ['vehicle_id' => $vehicle->id, 'lender' => 'Banque fictive', 'reference' => 'ERREUR-CONTRAT', 'tracking_from' => '2026-10-01', 'schedule_confirmed' => true, 'opening_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [['due_on' => '2026-10-05', 'amount_centimes' => 250000, 'opening_paid_centimes' => $opening]]];
        $loan = $this->postJson('/api/v1/loans', $create)->assertCreated()->json('id');
        $row = $this->getJson("/api/v1/loans/$loan")->json('installments.0.id');
        $payment = null;
        if ($paid > 0) {
            $payment = $this->postJson("/api/v1/loans/$loan/installments/$row/payments", $this->paymentInput($paid, 250000 - $opening))->assertCreated()->json('id');
        }

        return [$loan, $row, $company, $member, $payment, $create];
    }

    private function paymentInput(int $amount = 50000, int $remaining = 250000): array
    {
        return ['amount_centimes' => $amount, 'paid_on' => '2026-10-18', 'method' => 'transfer', 'receipt_confirmed' => true, 'expected_remaining_centimes' => $remaining, 'request_key' => (string) Str::uuid()];
    }

    private function input(int $loan): array
    {
        $data = $this->getJson("/api/v1/loans/$loan")->assertOk()->json();

        return ['reason' => 'Dossier créé pour la mauvaise voiture', 'confirmed' => true, 'financial_revision' => $data['financial_revision'], 'expected_loan_remaining_centimes' => $data['data']['remaining_centimes'], 'request_key' => (string) Str::uuid()];
    }

    public function test_empty_contract_moves_to_archive_and_disappears_from_all_active_totals(): void
    {
        [$loan, $row, , $member, , $create] = $this->fixture();
        $active = $this->postJson('/api/v1/loans', [...$create, 'reference' => 'ACTIVE', 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $original = DB::table('vehicle_loans')->get()->toJson();
        $schedule = DB::table('vehicle_loan_installments')->get()->toJson();
        $input = $this->input($loan);
        $url = "/api/v1/loans/$loan/cancellations";
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson($url, [...$input, 'reason' => 'Autre raison'])->assertConflict();
        $this->postJson($url, [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/v1/loans')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active)->assertJsonPath('summary.loan_count', 1)->assertJsonPath('summary.remaining_centimes', 250000)->assertJsonPath('summary.overdue_centimes', 250000)->assertJsonPath('summary.month_centimes', 250000);
        $this->getJson('/api/v1/loans?filter=cancelled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $loan)->assertJsonPath('data.0.cancellation_id', $id)->assertJsonPath('summary.remaining_centimes', 250000);
        foreach (['all', 'overdue', 'upcoming', 'settled'] as $filter) {
            $this->getJson('/api/v1/loans?q=ERREUR-CONTRAT&filter='.$filter)->assertJsonCount(0, 'data')->assertJsonPath('summary.remaining_centimes', 0)->assertJsonPath('summary.month_centimes', 0)->assertJsonPath('summary.overdue_centimes', 0);
        }
        $this->getJson("/api/v1/loans/$loan")->assertOk()->assertJsonPath('data.cancellation_id', $id)->assertJsonPath('cancellation.reason', $input['reason'])->assertJsonPath('cancellation.actor', $member->user->name)->assertJsonPath('cancellation.before_remaining_centimes', 250000)->assertJsonPath('installments.0.id', $row)->assertJsonPath('installments.0.amount_centimes', 250000);
        $this->assertSame($original, DB::table('vehicle_loans')->get()->toJson());
        $this->assertSame($schedule, DB::table('vehicle_loan_installments')->get()->toJson());
        $this->assertDatabaseCount('vehicle_loan_cancellations', 1);
        $this->assertDatabaseHas('vehicle_events', ['action' => 'loan_cancelled', 'actor_membership_id' => $member->id]);
        $this->postJson('/api/v1/loans', [...$create, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_active_payment_blocks_contract_cancellation_until_erroneous_payment_is_cancelled(): void
    {
        [$loan, $row, , , $payment] = $this->fixture(0, 50000);
        $input = $this->input($loan);
        $this->postJson("/api/v1/loans/$loan/cancellations", $input)->assertUnprocessable()->assertJsonValidationErrors('loan');
        $this->assertDatabaseCount('vehicle_loan_cancellations', 0);
        $paymentCancel = [...$input, 'correction_revision' => 0, 'expected_remaining_centimes' => 200000, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/loans/$loan/payments/$payment/cancellations", $paymentCancel)->assertCreated();
        $this->postJson("/api/v1/loans/$loan/cancellations", $input)->assertConflict();
        $this->postJson("/api/v1/loans/$loan/cancellations", $this->input($loan))->assertCreated();
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('payments.0.amount_centimes', 50000)->assertJsonPath('payments.0.effective_amount_centimes', 0)->assertJsonCount(1, 'cancellations');
        $this->postJson("/api/v1/loans/$loan/installments/$row/payments", $this->paymentInput())->assertConflict();
        $change = [...$this->input($loan), 'correction_revision' => 0, 'expected_remaining_centimes' => 250000];
        $this->postJson("/api/v1/loans/$loan/payments/$payment/corrections", [...$change, 'amount_centimes' => 10000])->assertConflict();
        $this->postJson("/api/v1/loans/$loan/payments/$payment/cancellations", $change)->assertConflict();
        $this->assertDatabaseCount('vehicle_loan_payments', 1);
    }

    public function test_historical_paid_amount_blocks_cancellation_even_without_new_payments(): void
    {
        [$loan] = $this->fixture(50000);
        $this->postJson("/api/v1/loans/$loan/cancellations", $this->input($loan))->assertUnprocessable()->assertJsonValidationErrors('loan')->assertJsonPath('errors.loan.0', 'Annulation bloquée : des montants déjà réglés avant le suivi sont enregistrés dans ce contrat. Ils doivent être vérifiés et régularisés séparément, sans effacer l’historique.');
        $this->getJson("/api/v1/loans/$loan")->assertJsonPath('data.opening_paid_centimes', 50000)->assertJsonPath('cancellation', null);
        $this->assertDatabaseCount('vehicle_loan_cancellations', 0);
    }

    public function test_fully_paid_real_contract_is_settled_not_cancellable(): void
    {
        [$loan] = $this->fixture(0, 250000);
        $this->postJson("/api/v1/loans/$loan/cancellations", $this->input($loan))->assertUnprocessable()->assertJsonValidationErrors('loan');
        $this->getJson('/api/v1/loans?filter=settled')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $loan);
    }

    public function test_archive_preserves_private_documents_but_rejects_new_attachments(): void
    {
        Storage::fake('vehicle_documents');
        [$loan] = $this->fixture();
        $document = $this->postJson("/api/v1/loans/$loan/documents", ['file' => UploadedFile::fake()->createWithContent('contrat.pdf', '%PDF-1.4 test'), 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->postJson("/api/v1/loans/$loan/cancellations", $this->input($loan))->assertCreated();
        $this->get("/api/v1/loans/$loan/documents/$document/content")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/loans/$loan/documents", ['file' => UploadedFile::fake()->createWithContent('autre.pdf', '%PDF-1.4 autre'), 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('vehicle_loan_documents', 1);
        $this->assertCount(1, Storage::disk('vehicle_documents')->allFiles());
    }

    public function test_reason_confirmation_and_fresh_financial_preview_are_required(): void
    {
        [$loan, $row] = $this->fixture();
        $input = $this->input($loan);
        $url = "/api/v1/loans/$loan/cancellations";
        foreach (['', '   ', str_repeat('a', 501)] as $reason) {
            $this->postJson($url, [...$input, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->postJson($url, [...$input, 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson($url, [...$input, 'company_id' => 1])->assertUnprocessable();
        $this->postJson($url, [...$input, 'expected_loan_remaining_centimes' => 0])->assertConflict();
        $this->postJson("/api/v1/loans/$loan/installments/$row/payments", $this->paymentInput())->assertCreated();
        $this->postJson($url, $input)->assertConflict();
        $this->assertDatabaseCount('vehicle_loan_cancellations', 0);
    }

    public function test_authentication_tenant_and_role_boundaries_and_append_only_archive(): void
    {
        [$loan, , $company, $member] = $this->fixture();
        $input = $this->input($loan);
        $url = "/api/v1/loans/$loan/cancellations";
        auth()->forgetGuards();
        $this->postJson($url, $input)->assertUnauthorized();
        $other = Company::factory()->create();
        $otherMember = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherMember->user)->withSession(['company_id' => $other->id]);
        $this->postJson($url, $input)->assertNotFound();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->withSession(['company_id' => $company->id]);
        $this->postJson($url, $input)->assertForbidden();
        $this->actingAs($member->user)->withSession(['company_id' => null]);
        $this->postJson($url, $input)->assertForbidden();
        $this->withSession(['company_id' => $company->id]);
        $this->postJson($url, $input)->assertCreated();
        foreach (["UPDATE vehicle_loan_cancellations SET reason='changed'", 'DELETE FROM vehicle_loan_cancellations', 'TRUNCATE vehicle_loan_cancellations'] as $sql) {
            $rejected = false;
            try {
                DB::transaction(fn () => DB::statement($sql));
            } catch (QueryException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
        $this->actingAs($otherMember->user)->withSession(['company_id' => $other->id]);
        $this->getJson("/api/v1/loans/$loan")->assertNotFound();
        $this->getJson('/api/v1/loans?filter=cancelled')->assertJsonPath('total', 0);
        $record = (array) DB::table('vehicle_loan_cancellations')->first();
        unset($record['id']);
        $record['company_id'] = $other->id;
        $record['actor_membership_id'] = $otherMember->id;
        $record['request_key'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('vehicle_loan_cancellations')->insert($record));
    }

    public function test_audit_failure_rolls_back_cancellation_and_keeps_debt_visible(): void
    {
        [$loan] = $this->fixture();
        $input = $this->input($loan);
        $this->partialMock(VehicleLoans::class, fn ($mock) => $mock->shouldReceive('audit')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        $this->postJson("/api/v1/loans/$loan/cancellations", $input)->assertStatus(500);
        $this->assertDatabaseCount('vehicle_loan_cancellations', 0);
        $this->getJson('/api/v1/loans')->assertJsonPath('summary.remaining_centimes', 250000)->assertJsonPath('total', 1);
    }
}
