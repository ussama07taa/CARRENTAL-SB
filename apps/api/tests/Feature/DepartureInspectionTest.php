<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DepartureInspectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $confirmed = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('inspection_photos');
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

    public function test_atomic_inspection_photos_reading_and_replay_keep_rental_confirmed(): void
    {
        [, , , $car, $id] = $this->fixture();
        $input = $this->payload($id);
        $response = $this->inspect($id, $input)->assertCreated()->assertJsonPath('data.completed', true)->assertJsonPath('data.latest.fuel_eighths', 6);
        $photo = $response->json('data.latest.photos.0.id');
        $this->inspect($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('rental_departure_inspections', 1);
        $this->assertDatabaseCount('rental_inspection_photos', 1);
        $this->assertSame(1, DB::table('vehicle_odometer_readings')->where('request_key', $input['request_key'])->count());
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'departure_inspection_recorded')->count());
        $this->assertDatabaseHas('vehicles', ['id' => $car->id, 'current_odometer' => $input['kilometers']]);
        $this->getJson("/api/v1/reservations/$id/departure")->assertJsonPath('data.inspection_done', true)->assertJsonPath('data.can_pick_up', false)->assertJsonPath('data.contract_signed', false);
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.status', 'confirmee')->assertJsonPath('financial.total_centimes', 270000);
        $this->get("/api/v1/reservations/$id/departure/inspection/photos/$photo/content")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertCount(1, Storage::disk('inspection_photos')->allFiles());
        $this->inspect($id, [...$input, 'fuel_eighths' => 7])->assertConflict();
    }

    public function test_validation_and_unconfirmed_requests_do_not_write(): void
    {
        [, , , , $id] = $this->fixture();
        foreach ([['photos' => []], ['fuel_eighths' => 9], ['kilometers' => -1], ['checked' => false], ['inspected_on' => '2026-10-20'], ['condition' => 'dommages_existants', 'notes' => null], ['photos' => [UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')]]] as $invalid) {
            $this->inspect($id, $this->payload($id, $invalid))->assertUnprocessable();
        }
        [, , , , $request] = $this->fixture(false);
        $this->inspect($request, $this->payload($request))->assertUnprocessable();
        $this->assertDatabaseCount('rental_departure_inspections', 0);
        $this->assertCount(0, Storage::disk('inspection_photos')->allFiles());
    }

    public function test_revisions_preserve_original_bytes_and_reject_stale_writers_or_lower_reading(): void
    {
        [, , , , $id] = $this->fixture();
        $first = $this->payload($id);
        $initial = $this->inspect($id, $first)->assertCreated()->json('data.latest');
        $this->inspect($id, [...$first, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->inspect($id, $this->payload($id, ['kilometers' => $first['kilometers'] - 1]))->assertUnprocessable();
        $update = $this->payload($id, ['photos' => [], 'captions' => [], 'retained_photo_ids' => [$initial['photos'][0]['id']], 'fuel_eighths' => 4, 'condition' => 'dommages_existants', 'notes' => 'Rayure ancienne sur la porte', 'reason' => 'Correction du niveau de carburant']);
        $this->inspect($id, $update)->assertCreated()->assertJsonPath('data.latest.previous_id', $initial['id'])->assertJsonPath('data.completed', true);
        $this->assertDatabaseHas('rental_departure_inspections', ['id' => $initial['id'], 'fuel_eighths' => 6, 'condition' => 'bon']);
        $this->assertDatabaseCount('rental_inspection_photos', 2);
        $this->assertCount(1, Storage::disk('inspection_photos')->allFiles());
        $this->get("/api/v1/reservations/$id/departure/inspection/photos/{$initial['photos'][0]['id']}/content")->assertOk();
        $this->inspect($id, $this->payload($id, ['photos' => [], 'retained_photo_ids' => [$initial['photos'][0]['id']]]))->assertUnprocessable();
    }

    public function test_blocking_anomaly_and_changed_vehicle_reading_require_new_control(): void
    {
        [, , , $car, $id] = $this->fixture();
        $this->inspect($id, $this->payload($id, ['condition' => 'anomalie_bloquante', 'notes' => 'Pneu endommagé']))->assertCreated()->assertJsonPath('data.completed', false);
        $this->getJson("/api/v1/reservations/$id/departure")->assertJsonPath('data.inspection_done', false);
        $this->inspect($id, $this->payload($id, ['reason' => 'Pneu réparé puis contrôlé']))->assertCreated()->assertJsonPath('data.completed', true);
        DB::table('vehicles')->where('id', $car->id)->increment('current_odometer');
        $this->getJson("/api/v1/reservations/$id/departure/inspection")->assertJsonPath('data.stale', true)->assertJsonPath('data.completed', false);
    }

    public function test_employee_can_record_operational_control_but_other_tenants_cannot_read_photos(): void
    {
        [$company, , , , $id] = $this->fixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $input = $this->payload($id);
        $photo = $this->inspect($id, $input)->assertCreated()->json('data.latest.photos.0.id');
        $this->fixture();
        $this->getJson("/api/v1/reservations/$id/departure/inspection")->assertNotFound();
        $this->inspect($id, $input)->assertNotFound();
        $this->getJson("/api/v1/reservations/$id/departure/inspection/photos/$photo/content")->assertNotFound();
    }

    public function test_storage_failure_rolls_back_control_reading_event_and_new_files(): void
    {
        [, , , $car, $id] = $this->fixture();
        $input = $this->payload($id);
        $events = DB::table('reservation_events')->count();
        Storage::shouldReceive('disk')->with('inspection_photos')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('delete')->once()->andReturn(true);
        $this->inspect($id, $input)->assertServerError();
        $this->assertDatabaseCount('rental_departure_inspections', 0);
        $this->assertDatabaseCount('rental_inspection_photos', 0);
        $this->assertDatabaseCount('reservation_events', $events);
        $this->assertDatabaseMissing('vehicle_odometer_readings', ['request_key' => $input['request_key']]);
        $this->assertDatabaseHas('vehicles', ['id' => $car->id, 'current_odometer' => $car->current_odometer]);
    }

    public function test_inspections_are_immutable(): void
    {
        [, , , , $id] = $this->fixture();
        $this->inspect($id, $this->payload($id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('rental_departure_inspections')->update(['kilometers' => 1]);
    }

    public function test_video_and_multiple_photos_are_private_immutable_replayable_and_retained(): void
    {
        [, , , , $id] = $this->fixture();
        $input = $this->payload($id);
        $videoBytes = file_get_contents(base_path('tests/Fixtures/inspection.webm'));
        $input['photos'][] = UploadedFile::fake()->createWithContent('tour.webm', $videoBytes);
        $input['photos'][] = UploadedFile::fake()->createWithContent('rear.png', file_get_contents($input['photos'][0]->getRealPath()));
        $input['captions'] = ['Avant', 'Tour vidéo', 'Arrière'];
        $latest = $this->inspect($id, $input)->assertCreated()->assertJsonCount(3, 'data.latest.photos')->json('data.latest');
        $media = $latest['photos'][1];
        $this->assertSame('video/webm', $media['mime']);
        $this->assertSame($videoBytes, $this->get("/api/v1/reservations/$id/departure/inspection/photos/{$media['id']}/content")->assertOk()->assertHeader('Content-Type', 'video/webm')->streamedContent());
        $this->inspect($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertCount(3, Storage::disk('inspection_photos')->allFiles());
        $this->inspect($id, $this->payload($id, ['photos' => [], 'captions' => [], 'retained_photo_ids' => array_column($latest['photos'], 'id')]))->assertCreated()->assertJsonCount(3, 'data.latest.photos');
        $this->assertCount(3, Storage::disk('inspection_photos')->allFiles());
        $this->assertSame($videoBytes, $this->get("/api/v1/reservations/$id/departure/inspection/photos/{$media['id']}/content")->assertOk()->streamedContent());
        $this->fixture();
        $this->getJson("/api/v1/reservations/$id/departure/inspection/photos/{$media['id']}/content")->assertNotFound();
    }

    public function test_video_never_replaces_required_photo_and_invalid_media_write_nothing(): void
    {
        [, , , , $id] = $this->fixture();
        $video = UploadedFile::fake()->createWithContent('tour.webm', file_get_contents(base_path('tests/Fixtures/inspection.webm')));
        $this->inspect($id, $this->payload($id, ['photos' => [$video]]))->assertUnprocessable();
        $this->inspect($id, $this->payload($id, ['photos' => [UploadedFile::fake()->createWithContent('fake.mp4', '<html>not a video</html>')]]))->assertUnprocessable();
        $this->inspect($id, $this->payload($id, ['photos' => [$video->size(20481)]]))->assertUnprocessable();
        $this->inspect($id, $this->payload($id, ['photos' => array_fill(0, 4, $video->size(20480))]))->assertUnprocessable();
        $this->inspect($id, $this->payload($id, ['photos' => array_fill(0, 13, $video->size(1))]))->assertUnprocessable();
        $this->assertDatabaseCount('rental_departure_inspections', 0);
        $this->assertCount(0, Storage::disk('inspection_photos')->allFiles());
    }
}
