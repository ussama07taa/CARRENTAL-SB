<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleMaintenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleMaintenanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupVehicle(bool $responsible = true): array
    {
        $c = Company::factory()->create();

        return Tenant::run($c->id, fn () => [CompanyMembership::factory()->create(['is_primary_responsible' => $responsible]), Vehicle::factory()->create()]);
    }

    private function login(CompanyMembership $m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['kind' => 'oil', 'title' => 'Vidange fictive', 'performed_on' => '2026-10-01', 'kilometers' => 45000, 'cost_centimes' => 65000, 'paid_centimes' => 20000, 'paid_on' => '2026-10-01', 'method' => 'cash', 'next_km' => 55000, 'next_on' => '2027-04-01', 'in_garage' => false, 'request_key' => (string) Str::uuid()], $extra);
    }

    public function test_partial_payment_balance_history_and_idempotency(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $input = $this->input();
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.balance_centimes', 45000)->assertJsonPath('summary.cost_centimes', 65000)->assertJsonPath('odometer.value', 45000);
        $payment = ['amount_centimes' => 15000, 'paid_on' => '2026-10-02', 'method' => 'transfer', 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/payments", $payment)->assertCreated();
        $this->postJson("$url/$id/payments", $payment)->assertOk();
        $this->postJson("$url/$id/payments", [...$payment, 'amount_centimes' => 16000])->assertConflict();
        $this->getJson($url)->assertJsonPath('data.0.balance_centimes', 30000);
        $this->postJson("$url/$id/payments", [...$payment, 'request_key' => (string) Str::uuid(), 'amount_centimes' => 30001])->assertUnprocessable();
        $this->postJson("$url/$id/payments", [...$payment, 'request_key' => (string) Str::uuid(), 'amount_centimes' => 30000])->assertCreated();
        $this->getJson($url)->assertJsonPath('summary.balance_centimes', 0)->assertJsonCount(0, 'debts');
        $this->getJson("$url/$id")->assertOk()->assertJsonCount(3, 'payments')->assertJsonPath('payments.0.actor', $m->user->name);
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertDatabaseCount('maintenance_payments', 3);
    }

    public function test_invalid_money_dates_and_odometer_leave_no_records(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        foreach ([['cost_centimes' => 1.5], ['cost_centimes' => -1], ['paid_centimes' => 65001], ['paid_on' => null], ['next_km' => 44000], ['next_on' => '2026-09-01'], ['performed_on' => '2099-01-01'], ['company_id' => 999]] as $bad) {
            $this->postJson($url, $this->input($bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('vehicle_maintenance', 0);
        $this->assertDatabaseCount('maintenance_payments', 0);
    }

    public function test_no_payment_is_not_a_fake_payment_and_future_payment_date_rejected(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson($url, $this->input(['paid_centimes' => 0, 'paid_on' => null, 'method' => null]))->assertCreated()->json('id');
        $this->assertDatabaseCount('maintenance_payments', 0);
        foreach ([0, -1, 1.5, 65001] as $value) {
            $this->postJson("$url/$id/payments", ['amount_centimes' => $value, 'method' => 'cash', 'paid_on' => '2026-10-01', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson("$url/$id/payments", ['amount_centimes' => 100, 'method' => 'cash', 'paid_on' => '2099-01-01', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_garage_return_is_independent_of_unpaid_balance(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson($url, $this->input(['kind' => 'repair', 'in_garage' => true]))->assertCreated()->json('id');
        $this->getJson($url)->assertJsonCount(1, 'summary.garage');
        $input = ['returned_on' => '2026-10-02', 'note' => 'Réparation contrôlée, véhicule prêt', 'ready' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("$url/$id/return", [...$input, 'ready' => false])->assertUnprocessable();
        $this->postJson("$url/$id/return", $input)->assertCreated();
        $this->postJson("$url/$id/return", $input)->assertOk();
        $this->postJson("$url/$id/return", [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson($url)->assertJsonCount(0, 'summary.garage')->assertJsonPath('summary.balance_centimes', 45000)->assertJsonPath('data.0.returned_on', '2026-10-02');
    }

    public function test_reminder_boundaries_and_unknown_odometer(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Casablanca'));
        try {
            $service = new VehicleMaintenance;
            foreach ([[-1, 'overdue'], [0, 'due'], [1, 'urgent'], [7, 'urgent'], [8, 'soon'], [30, 'soon'], [31, 'scheduled']] as [$days,$state]) {
                $this->assertSame($state, $service->deadline(CarbonImmutable::today('Africa/Casablanca')->addDays($days)->toDateString(), null, null)['state']);
            }
            foreach ([[-1, 'overdue'], [0, 'due'], [1, 'soon'], [1000, 'soon'], [1001, 'scheduled']] as [$distance,$state]) {
                $this->assertSame($state, $service->deadline(null, 50000 + $distance, 50000)['state']);
            }
            $this->assertTrue($service->deadline(null, 55000, null)['needs_odometer']);
            $this->assertSame('soon', $service->deadline('2027-01-01', 55000, 54500)['state']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_followup_replaces_reminder_without_erasing_history_and_historical_oil_not_newest(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $id = $this->postJson($url, $this->input())->assertCreated()->json('id');
        $this->postJson($url, $this->input(['performed_on' => '2026-09-01', 'kilometers' => 40000]))->assertCreated();
        $this->getJson($url)->assertJsonCount(1, 'reminders')->assertJsonPath('reminders.0.id', $id)->assertJsonPath('odometer.value', 45000);
        $next = $this->input(['performed_on' => '2026-10-02', 'kilometers' => 55000, 'next_km' => 65000, 'replaces_id' => $id]);
        $this->postJson($url, $next)->assertCreated();
        $this->postJson($url, [...$next, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson($url)->assertJsonCount(1, 'reminders')->assertJsonCount(3, 'data')->assertJsonPath('odometer.value', 55000);
    }

    public function test_private_invoice_and_cross_tenant_and_parent_access(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $pdf = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $id = $this->postJson($url, $this->input(['file' => $pdf]))->assertCreated()->json('id');
        $this->get("$url/$id/content")->assertOk()->assertDownload("intervention-$id.pdf")->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson($url)->assertJsonMissingPath('data.0.path')->assertJsonPath('data.0.has_file', true);
        [$other,$car] = $this->setupVehicle();
        $this->login($other);
        $this->getJson($url)->assertNotFound();
        $this->getJson("$url/$id/content")->assertNotFound();
        $this->getJson("/api/v1/vehicles/$car->id/maintenance/$id")->assertNotFound();
        $this->postJson("/api/v1/vehicles/$car->id/maintenance/$id/payments", ['amount_centimes' => 100, 'method' => 'cash', 'paid_on' => '2026-10-02', 'request_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_guests_and_non_responsible_cannot_access_financial_module(): void
    {
        [$m,$v] = $this->setupVehicle(false);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $this->getJson($url)->assertUnauthorized();
        $this->login($m);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url, $this->input())->assertForbidden();
    }

    public function test_fake_invoice_rejected_and_history_immutable(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        $this->postJson($url, $this->input(['file' => UploadedFile::fake()->createWithContent('invoice.pdf', 'fake file')]))->assertUnprocessable();
        $this->postJson($url, $this->input())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('maintenance_payments')->update(['amount_centimes' => 1]);
    }

    public function test_audit_failure_rolls_back_cost_payment_odometer_and_invoice(): void
    {
        Storage::fake('vehicle_documents');
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        DB::unprepared("CREATE FUNCTION fail_maintenance_event() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit fail'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER fail_maintenance_event BEFORE INSERT ON vehicle_events FOR EACH ROW EXECUTE FUNCTION fail_maintenance_event();");
        $pdf = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $this->postJson("/api/v1/vehicles/$v->id/maintenance", $this->input(['file' => $pdf]))->assertServerError();
        $this->assertDatabaseCount('vehicle_maintenance', 0);
        $this->assertDatabaseCount('maintenance_payments', 0);
        $this->assertDatabaseCount('vehicle_odometer_readings', 0);
        $this->assertEmpty(Storage::disk('vehicle_documents')->allFiles());
    }

    public function test_old_service_can_show_overdue_km_without_rewinding_current_odometer(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        Tenant::run($m->company_id, function () use ($v): void {
            $v->current_odometer = 60000;
            $v->current_odometer_date = '2026-10-10';
            $v->save();
        });
        $this->postJson($url, $this->input(['performed_on' => '2026-09-01', 'kilometers' => 45000, 'next_km' => 55000]))->assertCreated();
        $this->getJson($url)->assertJsonPath('reminders.0.state', 'overdue')->assertJsonPath('reminders.0.km', -5000)->assertJsonPath('odometer.value', 60000);
    }

    public function test_one_return_does_not_close_other_garage_work_and_history_is_paginated(): void
    {
        [$m,$v] = $this->setupVehicle();
        $this->login($m);
        $url = "/api/v1/vehicles/$v->id/maintenance";
        for ($i = 0; $i < 21; $i++) {
            $response = $this->postJson($url, $this->input(['kind' => 'repair', 'in_garage' => true, 'next_on' => null, 'next_km' => null]))->assertCreated();
        }
        $this->getJson($url)->assertJsonCount(20, 'data')->assertJsonCount(21, 'summary.garage')->assertJsonPath('total', 21);
        $this->getJson($url.'?page=2')->assertJsonCount(1, 'data');
        $this->postJson($url.'/'.$response->json('id').'/return', ['returned_on' => '2026-10-02', 'note' => 'Test', 'ready' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson($url)->assertJsonCount(20, 'summary.garage');
    }
}
