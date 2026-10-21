<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleLoanConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_partial_payments_cannot_overpay_an_installment(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required.');
        }
        $company = Company::factory()->create();
        [$m,$v] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($m->user)->withSession(['company_id' => $company->id]);
        $today = now('Africa/Casablanca')->toDateString();
        $id = $this->postJson('/api/v1/loans', ['vehicle_id' => $v->id, 'lender' => 'Test', 'reference' => 'Race', 'tracking_from' => $today, 'installments' => [['due_on' => $today, 'amount_centimes' => 300000, 'opening_paid_centimes' => 100000]], 'opening_confirmed' => true, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $row = $this->getJson("/api/v1/loans/$id")->assertOk()->json('installments.0.id');
        DB::disconnect();
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'loan-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork');
                }if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }$response = $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => 150000, 'paid_on' => $today, 'method' => 'transfer', 'expected_remaining_centimes' => 200000, 'receipt_confirmed' => true, 'request_key' => (string) Str::uuid()]);
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
            $this->assertSame(['201', '409'], $results);
            $this->assertDatabaseCount('vehicle_loan_payments', 1);
            $this->getJson("/api/v1/loans/$id")->assertJsonPath('data.remaining_centimes', 50000)->assertJsonPath('data.opening_paid_centimes', 100000)->assertJsonPath('data.recorded_paid_centimes', 150000);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_simultaneous_corrections_cannot_apply_two_stale_previews(): void
    {
        $this->correctionRace(false);
    }

    public function test_payment_and_correction_serialize_and_reject_a_stale_preview(): void
    {
        $this->correctionRace(true);
    }

    public function test_simultaneous_cancellations_only_remove_the_payment_once(): void
    {
        $this->correctionRace(false, 'cancel');
    }

    public function test_cancellation_and_correction_cannot_both_use_the_same_preview(): void
    {
        $this->correctionRace(false, 'correct');
    }

    public function test_cancellation_and_payment_serialize_the_installment_balance(): void
    {
        $this->correctionRace(true, 'pay');
    }

    private function correctionRace(bool $withPayment, string $cancellationOpponent = ''): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required.');
        }
        $company = Company::factory()->create();
        [$member,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $today = now('Africa/Casablanca')->toDateString();
        $id = $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Test', 'reference' => 'Corrections race', 'tracking_from' => $today, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [['due_on' => $today, 'amount_centimes' => 250000, 'opening_paid_centimes' => 0]]])->assertCreated()->json('id');
        $row = $this->getJson("/api/v1/loans/$id")->json('installments.0.id');
        $payment = $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => 100000, 'paid_on' => $today, 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 250000, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $d = $this->getJson("/api/v1/loans/$id")->json();
        $input = ['amount_centimes' => 10000, 'reason' => 'Un zéro en trop', 'confirmed' => true, 'correction_revision' => 0, 'financial_revision' => $d['financial_revision'], 'expected_remaining_centimes' => 150000, 'expected_loan_remaining_centimes' => 150000];
        DB::disconnect();
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'loan-correction-race-');
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
                        if ($cancellationOpponent !== '' && ($i === 0 || $cancellationOpponent === 'cancel')) {
                            $cancelInput = $input;
                            unset($cancelInput['amount_centimes']);
                            $response = $this->postJson("/api/v1/loans/$id/payments/$payment/cancellations", [...$cancelInput, 'request_key' => (string) Str::uuid()]);
                        } elseif ($withPayment && $i === 1) {
                            $response = $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => 50000, 'paid_on' => $today, 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 150000, 'request_key' => (string) Str::uuid()]);
                        } else {
                            $response = $this->postJson("/api/v1/loans/$id/payments/$payment/corrections", [...$input, 'amount_centimes' => $i === 0 ? 10000 : 20000, 'request_key' => (string) Str::uuid()]);
                        }
                        file_put_contents($file, (string) $response->status());
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
            $this->assertSame(['201', '409'], $results);
            $data = $this->getJson("/api/v1/loans/$id")->assertOk()->json('data');
            $this->assertContains($data['recorded_paid_centimes'], $cancellationOpponent !== '' ? ($withPayment ? [0, 150000] : [0, 20000]) : ($withPayment ? [10000, 150000] : [10000, 20000]));
            $this->assertSame(250000 - $data['recorded_paid_centimes'], $data['remaining_centimes']);
            $this->assertSame(100000, (int) DB::table('vehicle_loan_payments')->where('id', $payment)->value('amount_centimes'));
            if ($cancellationOpponent !== '') {
                $this->assertSame(1, DB::table('vehicle_loan_payment_cancellations')->count() + DB::table('vehicle_loan_payment_corrections')->count() + DB::table('vehicle_loan_payments')->count() - 1);
                if ($cancellationOpponent === 'cancel') {
                    $this->assertSame(0, $data['recorded_paid_centimes']);
                    $this->assertDatabaseCount('vehicle_loan_payment_cancellations', 1);
                }
            } elseif (! $withPayment) {
                $this->assertDatabaseCount('vehicle_loan_payment_corrections', 1);
                $this->assertDatabaseCount('vehicle_loan_payments', 1);
            }
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_contract_cancellation_race_only_archives_once(): void
    {
        $this->contractCancellationRace(false);
    }

    public function test_contract_cancellation_and_payment_cannot_both_commit(): void
    {
        $this->contractCancellationRace(true);
    }

    private function contractCancellationRace(bool $withPayment): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required.');
        }
        $company = Company::factory()->create();
        [$member,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $today = now('Africa/Casablanca')->toDateString();
        $id = $this->postJson('/api/v1/loans', ['vehicle_id' => $vehicle->id, 'lender' => 'Test', 'reference' => 'Corrections race', 'tracking_from' => $today, 'schedule_confirmed' => true, 'request_key' => (string) Str::uuid(), 'installments' => [['due_on' => $today, 'amount_centimes' => 250000, 'opening_paid_centimes' => 0]]])->assertCreated()->json('id');
        $row = $this->getJson("/api/v1/loans/$id")->json('installments.0.id');
        $d = $this->getJson("/api/v1/loans/$id")->json();
        $input = ['reason' => 'Dossier saisi par erreur', 'confirmed' => true, 'financial_revision' => $d['financial_revision'], 'expected_loan_remaining_centimes' => 250000];
        DB::disconnect();
        $files = [];
        $children = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'loan-correction-race-');
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
                        if ($withPayment && $i === 1) {
                            $response = $this->postJson("/api/v1/loans/$id/installments/$row/payments", ['amount_centimes' => 50000, 'paid_on' => $today, 'method' => 'cash', 'receipt_confirmed' => true, 'expected_remaining_centimes' => 250000, 'request_key' => (string) Str::uuid()]);
                        } else {
                            $response = $this->postJson("/api/v1/loans/$id/cancellations", [...$input, 'request_key' => (string) Str::uuid()]);
                        }
                        file_put_contents($file, (string) $response->status());
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
            $this->assertSame(['201', '409'], $results);
            $cancelled = DB::table('vehicle_loan_cancellations')->count();
            $this->assertSame(1, $cancelled + DB::table('vehicle_loan_payments')->count());
            $data = $this->getJson('/api/v1/loans')->assertOk()->json('summary');
            $this->assertSame($cancelled ? 0 : 200000, $data['remaining_centimes']);
            if (! $withPayment) {
                $this->assertSame(1, $cancelled);
            }
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
