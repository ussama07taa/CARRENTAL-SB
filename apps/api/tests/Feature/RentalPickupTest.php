<?php

namespace Tests\Feature;

use App\Jobs\RenderRentalContract;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Reservations\RentalPayment;
use App\Support\Reservations\RentalPickup;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentalPickupTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        if ($this->app && app()->environment('testing') && config('database.connections.pgsql.database') === 'bensebih_test') {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
        parent::tearDown();
    }

    private function fixture(bool $confirmed = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('inspection_photos');
        Storage::fake('rental_contracts');
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00Z'));
        $company = Company::factory()->create();
        [$actor, $client, $car] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(['identity_type' => 'cin', 'identity_number' => 'TEST123', 'licence_number' => 'LIC123', 'licence_expires_on' => '2030-01-01']), Vehicle::factory()->create()]);
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        if ($confirmed) {
            $quote = $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 270000, 'reason' => 'Prix convenu fictif', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
            $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 270000, 'method' => 'especes', 'received_on' => '2026-10-19', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
            $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        }

        return [$company, $actor, $client, $car, $id];
    }

    private function payload(int $id, array $changes = []): array
    {
        $data = $this->getJson("/api/v1/reservations/$id/departure/inspection")->assertOk()->json('data');

        return [...['expected_inspection_id' => $data['latest']['id'] ?? null, 'vehicle_version' => $data['vehicle']['version'], 'inspected_on' => '2026-10-19', 'kilometers' => max(20000, $data['vehicle']['current_odometer'] ?? 0), 'fuel_eighths' => 6, 'condition' => 'bon', 'notes' => null, 'reason' => 'Contrôle réel fictif', 'checked' => true, 'photos' => [UploadedFile::fake()->createWithContent('car.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII='))], 'captions' => ['Avant du véhicule'], 'request_key' => (string) Str::uuid()], ...$changes];
    }

    private function inspect(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/departure/inspection", $input);
    }

    private function readyFile(): array
    {
        $fixture = $this->fixture();
        [,,$client,,$id] = $fixture;
        $data = $this->getJson("/api/v1/reservations/$id/departure")->assertOk()->json('data');
        foreach ([['action' => 'documents_checked', 'fingerprint' => $data['documents']['fingerprint'], 'identity_original' => true, 'licence_original' => true], ['action' => 'caution_waived', 'expected_terms_id' => null]] as $action) {
            $this->postJson("/api/v1/reservations/$id/departure", [...$action, 'reason' => 'Décision de test fictive', 'request_key' => (string) Str::uuid()])->assertCreated();
        }
        $this->inspect($id, $this->payload($id))->assertCreated();

        return $fixture;
    }

    private function issueInput(int $id): array
    {
        $data = $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->json('data');

        return ['expected_latest_id' => $data['latest_id'], 'fingerprint' => $data['fingerprint'], 'reviewed' => true, 'reason' => 'Contrat de test fictif', 'request_key' => (string) Str::uuid()];
    }

    private function issue(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/departure/contract", $input);
    }

    private function render(int $company, int $contract): void
    {
        $pdf = \Mockery::mock(ContractPdf::class);
        $pdf->shouldReceive('render')->once()->andReturn("%PDF-1.4\noriginal bytes\n%%EOF");
        (new RenderRentalContract($company, $contract))->handle($pdf, app(RentalContract::class));
    }

    private function uploadInput(?int $previous = null): array
    {
        return ['file' => UploadedFile::fake()->createWithContent('signed.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII=')), 'reason' => 'Copie signée fictive', 'expected_signed_id' => $previous, 'same_version' => true, 'request_key' => (string) Str::uuid()];
    }

    private function approvedFixture(bool $demo = false, bool $durationReference = false, bool $circulation = true): array
    {
        $fixture = $this->readyFile();
        [$company,$actor,,$car,$id] = $fixture;
        Storage::fake('vehicle_documents');
        Tenant::run($company->id, function () use ($actor, $car, $circulation): void {
            foreach (['registration', 'insurance', 'inspection', 'circulation'] as $kind) {
                if ($kind === 'circulation' && ! $circulation) { continue; }
                $path = $kind.'.png';
                Storage::disk('vehicle_documents')->put($path, 'vehicle document fixture');
                VehicleDocument::create(['vehicle_id' => $car->id, 'actor_membership_id' => $actor->id, 'kind' => $kind, 'version' => 1, 'change_kind' => 'initial', 'effective_on' => $kind === 'registration' ? null : '2026-01-01', 'expires_on' => $kind === 'registration' ? null : '2030-01-01', 'disk' => 'vehicle_documents', 'path' => $path, 'mime' => 'image/png', 'size' => 24, 'sha256' => hash('sha256', 'vehicle document fixture'), 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'test')]);
            }
        });
        config(['rental_contracts.demo_only' => $demo, 'rental_contracts.model_version' => $demo ? 'demo-1' : 'approved-test-fixture']);
        if ($durationReference) {
            Tenant::run($company->id, function () use ($actor, $id): void {
                $quote = app(RentalPayment::class)->summary(Reservation::findOrFail($id))['quote_id'];
                app(RentalContract::class)->event($id, $actor->id, 'rental_price_agreed', ['quote_id' => $quote, 'pricing_snapshot' => ['agreement_mode' => 'negotiated', 'lines' => [['half_day_units' => 2], ['half_day_units' => 2], ['half_day_units' => 2]]]]);
            });
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-22T10:15:00Z'));
        Tenant::run($company->id, function () use ($actor, $id, $durationReference): void {
            $contracts = app(RentalContract::class);
            $snapshot = $contracts->snapshot(Reservation::findOrFail($id));
            if ($durationReference) {
                $snapshot['presentation'] = $contracts->presentation(Reservation::findOrFail($id));
                unset($snapshot['presentation']['pricing_half_day_units'], $snapshot['presentation']['pricing_agreement_mode']);
            }
            $v = DB::table('rental_contract_versions')->insertGetId(['company_id' => $actor->company_id, 'reservation_id' => $id, 'actor_membership_id' => $actor->id, 'version' => 1, 'snapshot' => json_encode($snapshot), 'fingerprint' => $contracts->fingerprint(collect($snapshot)->except('presentation')->all()), 'reason' => 'Approved fictional fixture, not a production contract', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'fixture'), 'created_at' => now()]);
            foreach (['original', 'signed'] as $kind) {
                $bytes = '%PDF-1.4 fictional '.$kind;
                $path = $v.'-'.$kind.'.pdf';
                Storage::disk('rental_contracts')->put($path, $bytes);
                $file = DB::table('rental_contract_files')->insertGetId(['company_id' => $actor->company_id, 'reservation_id' => $id, 'contract_id' => $v, 'actor_membership_id' => $actor->id, 'kind' => $kind, 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'reason' => 'Fixture fictive', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'fixture'), 'created_at' => now()]);
                if ($kind === 'signed') {
                    DB::table('rental_contract_verifications')->insert(['company_id' => $actor->company_id, 'reservation_id' => $id, 'contract_id' => $v, 'file_id' => $file, 'actor_membership_id' => $actor->id, 'reason' => 'Fixture vérifiée', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'fixture'), 'created_at' => now()]);
                }
            }
        });

        return $fixture;
    }

    private function pickupInput(int $id): array
    {
        $d = $this->getJson("/api/v1/reservations/$id/pickup")->assertOk()->json('data');

        return ['late_agreement_confirmed' => true, 'late_agreement_reason' => 'Client tardif : accord initial maintenu', 'picked_up_local' => $d['server_now_local'], 'review_fingerprint' => $d['review_fingerprint'], 'vehicle_present' => true, 'driver_reviewed' => true, 'keys_handed_over' => true, 'reason' => 'Remise effective fictive', 'request_key' => (string) Str::uuid()];
    }

    private function pickup(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/pickup", $input);
    }

    public function test_circulation_missing_future_expired_and_missing_file_block_pickup(): void
    {
        [$company,$actor,,$car,$id] = $this->approvedFixture(circulation: false);
        $attempt = function () use ($id): void {
            $data = $this->getJson("/api/v1/reservations/$id/pickup")->assertOk()->json('data');
            $gate = collect($data['checks'])->firstWhere('key', 'vehicle_documents');
            $this->assertFalse($gate['ok']);
            $this->assertStringContainsString('Feuille de circulation', $gate['detail']);
            $this->pickup($id, $this->pickupInput($id))->assertUnprocessable()->assertJsonPath('code', 'PICKUP_BLOCKED');
        };
        $attempt();
        $add = function (int $version, string $start, string $end, bool $file) use ($company,$actor,$car): void {
            Tenant::run($company->id, fn () => VehicleDocument::create(['vehicle_id' => $car->id, 'actor_membership_id' => $actor->id, 'kind' => 'circulation', 'version' => $version, 'change_kind' => 'renewal', 'effective_on' => $start, 'expires_on' => $end, 'disk' => $file ? 'vehicle_documents' : null, 'path' => $file ? 'circulation.png' : null, 'mime' => $file ? 'image/png' : null, 'size' => $file ? 24 : null, 'sha256' => $file ? hash('sha256','vehicle document fixture') : null, 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256','fixture')]));
        };
        $add(1, '2026-10-23', '2030-01-01', true); $attempt();
        $add(2, '2026-01-01', '2026-10-21', true); $attempt();
        $add(3, '2026-01-01', '2030-01-01', false); $attempt();
        $add(4, '2026-01-01', '2030-01-01', true);
        $this->pickup($id, $this->pickupInput($id))->assertUnprocessable();
        Storage::disk('vehicle_documents')->put('circulation.png', 'vehicle document fixture');
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
    }

    public function test_late_pickup_requires_explicit_agreement_and_sandbox_records_test_in_event(): void
    {
        [,,,,$id] = $this->approvedFixture();
        $input = $this->pickupInput($id);
        $before = DB::table('reservations')->where('id', $id)->first();
        $missing = $input;
        unset($missing['late_agreement_confirmed'], $missing['late_agreement_reason']);
        $this->pickup($id, $missing)->assertUnprocessable()->assertJsonValidationErrors('late_agreement_confirmed');
        $this->pickup($id, [...$input, 'late_agreement_confirmed' => false])->assertUnprocessable();
        $this->pickup($id, [...$input, 'late_agreement_reason' => '  '])->assertUnprocessable();
        $this->assertDatabaseCount('rental_pickups', 0);
        config(['rental_contracts.isolated_pickup_test' => true]);
        $this->pickup($id, $input)->assertCreated()->assertJsonPath('data.pickup.snapshot.demo_simulation', true)->assertJsonPath('data.pickup.snapshot.late_agreement.confirmed', true);
        $this->pickup($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $event = json_decode(DB::table('reservation_events')->where('action', 'rental_picked_up')->value('data'), true);
        $this->assertTrue($event['demo_simulation']);
        $this->assertSame(15, $event['late_agreement']['delay_minutes']);
        $after = DB::table('reservations')->where('id', $id)->first();
        foreach (['starts_at', 'ends_at', 'frozen_total_centimes', 'blocked_until'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
    }

    public function test_employee_pickup_is_atomic_and_idempotent_without_rewriting_agreement_or_money(): void
    {
        [$company,$actor,,,$id] = $this->approvedFixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $before = DB::table('reservations')->where('id', $id)->first();
        $input = $this->pickupInput($id);
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.can_record', true);
        $this->pickup($id, $input)->assertCreated()->assertJsonPath('data.status', 'en_location');
        $this->pickup($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->pickup($id, [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
        $this->assertDatabaseCount('rental_pickups', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'rental_picked_up')->count());
        $after = DB::table('reservations')->where('id', $id)->first();
        foreach (['frozen_total_centimes', 'starts_at', 'ends_at', 'blocked_until', 'preparation_minutes'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.status', 'en_location')->assertJsonPath('data.blocks_vehicle', true)->assertJsonPath('financial.total_centimes', 270000)->assertJsonCount(1, 'financial.payments');
        $this->getJson('/api/v1/reservations/planning?from=2026-10-19&days=14')->assertJsonPath('data.0.out_now', true)->assertJsonPath('data.0.events.0.kind', 'out');
    }

    public function test_demo_contract_cannot_be_overridden_by_ui_or_api(): void
    {
        [,,,,$id] = $this->approvedFixture(true);
        $input = $this->pickupInput($id);
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.can_record', false);
        $this->pickup($id, $input)->assertUnprocessable()->assertJsonPath('code', 'PICKUP_BLOCKED');
        $this->pickup($id, [...$input, 'contract_demo_override' => true])->assertUnprocessable();
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_simulation_is_opt_in_and_unavailable_outside_demo(): void
    {
        [,,,,$id] = $this->approvedFixture(true);
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.simulation_available', false);
        $this->pickup($id, [...$this->pickupInput($id), 'demo_simulation' => true, 'simulation_attested' => true])->assertForbidden();
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_demo_simulation_is_explicit_replayable_and_preserves_contract_bytes_and_money(): void
    {
        [,,,,$id] = $this->approvedFixture(true);
        $pickups = \Mockery::mock(RentalPickup::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $pickups->shouldReceive('simulationAllowed')->andReturnUsing(fn ($actor) => $actor->is_primary_responsible);
        $this->app->instance(RentalPickup::class, $pickups);
        $data = $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.can_record', false)->assertJsonPath('data.simulation_can_record', true)->json('data');
        $normal = $this->pickupInput($id);
        $this->pickup($id, $normal)->assertUnprocessable();
        $input = [...$normal, 'demo_simulation' => true, 'simulation_attested' => true, 'review_fingerprint' => $data['simulation_review_fingerprint']];
        $this->pickup($id, [...$input, 'simulation_attested' => false])->assertUnprocessable();
        $this->pickup($id, [...$input, 'review_fingerprint' => $data['review_fingerprint']])->assertConflict();
        $this->pickup($id, [...$input, 'picked_up_local' => '2026-10-25T10:00'])->assertUnprocessable();
        $original = DB::table('rental_contract_files')->where('kind', 'original')->first();
        $bytes = Storage::disk('rental_contracts')->get($original->path);
        $reservation = DB::table('reservations')->where('id', $id)->first();
        $this->pickup($id, $input)->assertCreated()->assertJsonPath('data.pickup.snapshot.demo_simulation', true)->assertJsonPath('data.status', 'en_location');
        $this->pickup($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('rental_pickups', 1);
        $event = json_decode(DB::table('reservation_events')->where('action', 'rental_picked_up')->value('data'), true);
        $this->assertTrue($event['demo_simulation']);
        $this->assertSame($bytes, Storage::disk('rental_contracts')->get($original->path));
        $after = DB::table('reservations')->where('id', $id)->first();
        foreach (['frozen_total_centimes', 'starts_at', 'ends_at', 'blocked_until'] as $field) {
            $this->assertSame($reservation->$field, $after->$field);
        }
    }

    public function test_simulation_cannot_skip_pending_payment_or_allow_employee_access(): void
    {
        [$company,,,,$id] = $this->approvedFixture(true);
        $pickups = \Mockery::mock(RentalPickup::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $pickups->shouldReceive('simulationAllowed')->andReturnUsing(fn ($actor) => $actor->is_primary_responsible);
        $this->app->instance(RentalPickup::class, $pickups);
        $data = $this->getJson("/api/v1/reservations/$id/pickup")->json('data');
        $input = [...$this->pickupInput($id), 'demo_simulation' => true, 'simulation_attested' => true, 'review_fingerprint' => $data['simulation_review_fingerprint']];
        $financial = \Mockery::mock(RentalPayment::class)->makePartial();
        $financial->shouldReceive('summary')->andReturn(['remaining_centimes' => 270000, 'verified_centimes' => 0, 'pending_centimes' => 270000]);
        $this->app->instance(RentalPayment::class, $financial);
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.simulation_can_record', false);
        $this->pickup($id, $input)->assertUnprocessable()->assertJsonPath('code', 'PICKUP_BLOCKED');
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->pickup($id, $input)->assertForbidden();
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_duration_rectification_of_out_demo_keeps_v1_money_dates_pickup_and_requires_new_signature(): void
    {
        [$company,$actor,,,$id] = $this->approvedFixture(true, true);
        $pickups = \Mockery::mock(RentalPickup::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $pickups->shouldReceive('simulationAllowed')->andReturn(true);
        $this->app->instance(RentalPickup::class, $pickups);
        $review = $this->getJson("/api/v1/reservations/$id/pickup")->json('data');
        $this->pickup($id, [...$this->pickupInput($id), 'demo_simulation' => true, 'simulation_attested' => true, 'review_fingerprint' => $review['simulation_review_fingerprint']])->assertCreated();
        $before = DB::table('reservations')->where('id', $id)->first();
        $pickup = DB::table('rental_pickups')->first();
        $v1 = DB::table('rental_contract_versions')->first();
        $files = DB::table('rental_contract_files')->get()->toArray();
        $input = [...$this->issueInput($id), 'duration_rectification' => true];
        $this->issue($id, $this->issueInput($id))->assertUnprocessable();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->issue($id, $input)->assertForbidden();
        $this->actingAs($actor->user);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$v1->id/signed", $this->uploadInput())->assertConflict();
        $this->issue($id, [...$input, 'expected_latest_id' => 999999])->assertConflict();
        $newId = $this->issue($id, $input)->assertCreated()->assertJsonPath('data.can_sign_rectification', true)->json('data.latest_id');
        $this->issue($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $v2 = DB::table('rental_contract_versions')->where('id', $newId)->first();
        $this->assertSame($v1->id, $v2->previous_id);
        $newSnapshot = json_decode($v2->snapshot, true);
        $oldSnapshot = json_decode($v1->snapshot, true);
        $this->assertSame(6, $newSnapshot['presentation']['pricing_half_day_units']);
        foreach (['reservation', 'customer', 'vehicle', 'inspection', 'caution', 'documents'] as $field) {
            $this->assertSame($oldSnapshot[$field], $newSnapshot[$field]);
        }
        $this->assertEquals($v1, DB::table('rental_contract_versions')->where('id', $v1->id)->first());
        $this->assertEquals($files, DB::table('rental_contract_files')->get()->toArray());
        $this->assertEquals($before, DB::table('reservations')->where('id', $id)->first());
        $this->assertEquals($pickup, DB::table('rental_pickups')->first());
        $this->assertSame(0, DB::table('rental_contract_files')->where('contract_id', $newId)->where('kind', 'signed')->count());
        $this->render($company->id, $newId);
        $this->postJson("/api/v1/reservations/$id/departure/contract/$newId/signed", $this->uploadInput())->assertCreated();
        $signed = DB::table('rental_contract_files')->where('contract_id', $newId)->where('kind', 'signed')->first();
        $this->postJson("/api/v1/reservations/$id/departure/contract/$newId/verify", ['file_id' => $signed->id, 'signatures_checked' => true, 'reason' => 'Nouvelles signatures de correction TEST', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseCount('rental_pickups', 1);
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.status', 'en_location');
    }

    public function test_missing_checks_time_window_and_changed_review_are_rejected(): void
    {
        [,$actor,$client,$car,$id] = $this->approvedFixture();
        $input = $this->pickupInput($id);
        foreach ([['keys_handed_over' => false], ['vehicle_present' => false], ['driver_reviewed' => false], ['reason' => ''], ['picked_up_local' => '2026-10-22T12:00'], ['picked_up_local' => '2026-10-22T09:59'], ['picked_up_local' => '2026-10-25T10:00']] as $values) {
            $this->pickup($id, [...$input, ...$values, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->pickup($id, [...$input, 'review_fingerprint' => str_repeat('0', 64)])->assertConflict();
        DB::table('customers')->where('id', $client->id)->update(['licence_expires_on' => '2026-10-20']);
        $this->pickup($id, $input)->assertUnprocessable();
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_expired_vehicle_document_or_pending_money_or_changed_caution_blocks_departure(): void
    {
        [$company,$actor,,$car,$id] = $this->approvedFixture();
        Tenant::run($company->id, fn () => VehicleDocument::create(['vehicle_id' => $car->id, 'actor_membership_id' => $actor->id, 'kind' => 'insurance', 'version' => 2, 'change_kind' => 'correction', 'effective_on' => '2026-01-01', 'expires_on' => '2026-10-21', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'fixture')]));
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.checks.6.ok', false);
        $this->pickup($id, $this->pickupInput($id))->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/departure", ['action' => 'caution_set', 'expected_terms_id' => DB::table('reservation_departure_entries')->where('action', 'caution_waived')->value('id'), 'amount_centimes' => 500000, 'reason' => 'Nouveau montant fictif', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.checks.3.ok', false);
        $financial = \Mockery::mock(RentalPayment::class)->makePartial();
        $financial->shouldReceive('summary')->andReturn(['remaining_centimes' => 270000, 'verified_centimes' => 0, 'pending_centimes' => 270000]);
        $this->app->instance(RentalPayment::class, $financial);
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.checks.2.ok', false);
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_open_garage_visit_and_changed_reading_prevent_pickup(): void
    {
        [,,,$car,$id] = $this->approvedFixture();
        $this->postJson("/api/v1/vehicles/$car->id/maintenance", ['kind' => 'oil', 'title' => 'Garage fictif ouvert', 'performed_on' => '2026-10-22', 'kilometers' => 20000, 'cost_centimes' => 65000, 'paid_centimes' => 20000, 'paid_on' => '2026-10-22', 'method' => 'cash', 'next_km' => 30000, 'next_on' => '2027-04-01', 'in_garage' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson("/api/v1/reservations/$id/pickup")->assertJsonPath('data.checks.7.ok', false)->assertJsonPath('data.checks.4.ok', false);
        $this->pickup($id, $this->pickupInput($id))->assertUnprocessable()->assertJsonPath('code', 'PICKUP_BLOCKED');
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_corrupt_contract_blocks_departure_and_event_failure_rolls_back_everything(): void
    {
        [$company,,,,$id] = $this->approvedFixture();
        $file = DB::table('rental_contract_files')->where('kind', 'signed')->first();
        $original = Storage::disk('rental_contracts')->get($file->path);
        Storage::disk('rental_contracts')->put($file->path, 'corrupt');
        $this->pickup($id, $this->pickupInput($id))->assertUnprocessable();
        Storage::disk('rental_contracts')->put($file->path, $original);
        $contracts = \Mockery::mock(RentalContract::class)->makePartial();
        $contracts->shouldReceive('event')->once()->andThrow(new \RuntimeException('Event write failed'));
        $this->app->instance(RentalContract::class, $contracts);
        $this->pickup($id, $this->pickupInput($id))->assertServerError();
        $this->assertDatabaseCount('rental_pickups', 0);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'confirmee']);
        $this->assertSame(0, DB::table('reservation_events')->where('action', 'rental_picked_up')->count());
    }

    public function test_future_booking_is_allowed_while_out_but_overdue_blocks_new_bookings_and_pickup(): void
    {
        [$company,$actor,$client,$car,$id] = $this->approvedFixture();
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        $next = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-26T10:00', 'ends_local' => '2026-10-29T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $q = $this->postJson("/api/v1/reservations/$next/quote", ['total_centimes' => 270000, 'reason' => 'Accord suivant', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
        $this->postJson("/api/v1/reservations/$next/payments", ['amount_centimes' => 270000, 'method' => 'especes', 'received_on' => '2026-10-22', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/reservations/$next/confirm", ['quote_id' => $q, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        try {
            DB::transaction(fn () => DB::table('reservations')->where('id', $next)->update(['status' => 'en_location']));
            $this->fail('Expected one physical vehicle out constraint.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }
        $this->getJson("/api/v1/reservations/$next/pickup")->assertJsonPath('data.checks.7.ok', false);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00Z'));
        $this->getJson("/api/v1/reservations/$next")->assertJsonPath('data.vehicle_overdue', true);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-26&days=7')->assertJsonPath('data.0.overdue', true)->assertJsonPath('data.0.events.1.threatened', true);
        $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-11-02T10:00', 'ends_local' => '2026-11-05T10:00', 'request_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->pickup($next, $this->pickupInput($next))->assertUnprocessable();
        $this->assertDatabaseCount('rental_pickups', 1);
    }

    public function test_other_tenant_cannot_read_or_record_and_pickup_history_is_immutable(): void
    {
        [,,,,$id] = $this->approvedFixture();
        $input = $this->pickupInput($id);
        $this->pickup($id, $input)->assertCreated();
        $this->fixture();
        $this->getJson("/api/v1/reservations/$id/pickup")->assertNotFound();
        $this->pickup($id, $input)->assertNotFound();
        $this->expectException(QueryException::class);
        DB::table('rental_pickups')->update(['reason' => 'Overwrite']);
    }

    public static function pickupRaces(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('pickupRaces')]
    public function test_concurrent_pickup_retries_create_one_transition_and_one_event(bool $sameKey): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for PostgreSQL race.');
        }
        [,,,,$id] = $this->approvedFixture();
        $input = $this->pickupInput($id);
        DB::disconnect();
        app('redis')->purge('default');
        app('redis')->purge('cache');
        $children = [];
        $files = [];
        $start = microtime(true) + 0.3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'pickup-race-');
                $files[] = $file;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork');
                }if ($pid === 0) {
                    try {
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }$attempt = $input;
                        if (! $sameKey && $i === 1) {
                            $attempt['request_key'] = (string) Str::uuid();
                        }$response = $this->pickup($id, $attempt);
                        file_put_contents($file, (string) $response->status());
                    } catch (\Throwable $e) {
                        file_put_contents($file, 'error '.$e->getMessage());
                    }DB::disconnect();
                    exit(0);
                }$children[] = $pid;
            }foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }$results = array_map('file_get_contents', $files);
            sort($results);
            $this->assertSame($sameKey ? ['200', '201'] : ['201', '422'], $results);
            $this->assertDatabaseCount('rental_pickups', 1);
            $this->assertSame(1, DB::table('reservation_events')->where('action', 'rental_picked_up')->count());
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
