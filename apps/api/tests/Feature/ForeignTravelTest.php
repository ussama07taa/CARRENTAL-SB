<?php

namespace Tests\Feature;

use App\Jobs\RenderForeignAuthorization;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Reservations\ForeignAuthorization;
use App\Support\Reservations\ForeignTravel;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class ForeignTravelTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $confirmed = false): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00Z'));
        $company = Company::factory()->create();
        [$actor,$client,$car] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(['identity_type' => 'cin', 'identity_number' => 'TEST123', 'licence_number' => 'TEST456', 'licence_expires_on' => '2030-01-01']), Vehicle::factory()->create()]);
        $this->actingAs($actor->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        if ($confirmed) {
            $quote = $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => 135000, 'reason' => 'Accord fictif', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
            $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 135000, 'method' => 'especes', 'received_on' => '2026-10-19', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
            $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        }

        return [$company, $actor, $client, $car, $id];
    }

    private function read(int $id): array
    {
        return $this->getJson("/api/v1/reservations/$id/foreign-travel")->assertOk()->json('data');
    }

    private function send(int $id, string $action, array $extra = [])
    {
        return $this->postJson("/api/v1/reservations/$id/foreign-travel", ['action' => $action, 'reason' => 'Vérification fictive du voyage', 'expected_revision' => $this->read($id)['revision'], 'request_key' => (string) Str::uuid(), ...$extra]);
    }

    private function trip(): array
    {
        return ['countries' => ['Espagne', 'France'], 'transit_countries' => ['Portugal'], 'starts_local' => '2026-10-22T11:00', 'ends_local' => '2026-10-24T18:00'];
    }

    private function insurance(): array
    {
        return ['insurer' => 'Assureur fictif', 'insurance_reference' => 'TEST-INT-123', 'insurance_starts_on' => '2026-10-20', 'insurance_expires_on' => '2026-10-30', 'coverage_attested' => true, 'responsible_approved' => true];
    }

    private function sheet(int $car, ?int $previous = null, string $start = '2026-10-01', string $end = '2026-10-30'): int
    {
        return $this->postJson("/api/v1/vehicles/$car/documents", ['kind' => 'circulation', 'change_kind' => $previous ? 'renewal' : 'initial', 'effective_on' => $start, 'expires_on' => $end, 'previous_id' => $previous, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    public function test_request_is_persistent_idempotent_and_does_not_change_booking_or_money(): void
    {
        [,,,,$id] = $this->fixture();
        $before = DB::table('reservations')->where('id', $id)->first();
        $events = DB::table('reservation_events')->count();
        $this->read($id);
        $this->assertDatabaseCount('reservation_events', $events);
        $key = (string) Str::uuid();
        $input = [...$this->trip(), 'request_key' => $key, 'expected_revision' => null];
        $this->send($id, 'request', $input)->assertCreated()->assertJsonPath('data.state', 'pending')->assertJsonPath('data.can_issue', false);
        $this->send($id, 'request', $input)->assertOk()->assertJsonPath('replayed', true);
        $this->send($id, 'request', [...$input, 'countries' => ['Italie']])->assertConflict();
        $this->send($id, 'request', [...$this->trip(), 'expected_revision' => null])->assertConflict();
        $this->assertEquals($before, DB::table('reservations')->where('id', $id)->first());
        $this->assertDatabaseCount('rental_payments', 0);
        $this->assertDatabaseCount('reservation_events', $events + 1);
    }

    public function test_dates_destinations_and_required_attestations_are_enforced_on_server(): void
    {
        [,,,$car,$id] = $this->fixture();
        $this->send($id, 'request', [...$this->trip(), 'ends_local' => '2026-10-26T10:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_local');
        $this->send($id, 'request', [...$this->trip(), 'countries' => []])->assertUnprocessable();
        $this->send($id, 'request', [...$this->trip(), 'countries' => ['Espagne', 'espagne']])->assertUnprocessable();
        $this->send($id, 'request', $this->trip())->assertCreated();
        $this->send($id, 'issue')->assertUnprocessable();
        $this->send($id, 'approve', $this->insurance())->assertUnprocessable();
        $this->sheet($car->id);
        $this->send($id, 'approve', [...$this->insurance(), 'coverage_attested' => false])->assertUnprocessable()->assertJsonValidationErrors('coverage_attested');
        $this->send($id, 'approve', [...$this->insurance(), 'insurance_expires_on' => '2026-10-23'])->assertUnprocessable()->assertJsonValidationErrors('insurance_expires_on');
        $this->send($id, 'approve', $this->insurance())->assertCreated()->assertJsonPath('data.state', 'approved')->assertJsonPath('data.can_issue', false);
    }

    public function test_employee_may_request_but_only_responsible_decides_and_other_tenants_cannot_read(): void
    {
        [$company,$actor,,$car,$id] = $this->fixture();
        $this->sheet($car->id);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->send($id, 'request', $this->trip())->assertCreated();
        $this->send($id, 'approve', $this->insurance())->assertForbidden();
        $this->send($id, 'refuse')->assertForbidden();
        $this->send($id, 'issue')->assertForbidden();
        $this->actingAs($actor->user);
        $this->send($id, 'approve', $this->insurance())->assertCreated();
        [$other,$otherActor] = $this->fixture();
        $this->getJson("/api/v1/reservations/$id/foreign-travel")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/foreign-travel", ['action' => 'refuse', 'reason' => 'Autre agence', 'expected_revision' => null, 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->withSession(['company_id' => null])->getJson("/api/v1/reservations/$id/foreign-travel")->assertForbidden();
    }

    public function test_revision_or_document_change_invalidates_agreement_and_keeps_history(): void
    {
        [,,,$car,$id] = $this->fixture();
        $sheet = $this->sheet($car->id);
        $this->send($id, 'request', $this->trip())->assertCreated();
        $this->send($id, 'approve', $this->insurance())->assertCreated();
        $this->postJson("/api/v1/vehicles/{$car->id}/documents", ['kind' => 'circulation', 'change_kind' => 'correction', 'reason' => 'Date rectifiée', 'effective_on' => '2026-10-01', 'expires_on' => '2026-10-23', 'previous_id' => $sheet, 'request_key' => (string) Str::uuid()])->assertCreated();
        $s = $this->read($id);
        $this->assertSame('stale', $s['state']);
        $this->assertFalse($s['can_issue']);
        $this->assertFalse($s['circulation_covers_trip']);
        $this->send($id, 'request', $this->trip())->assertCreated()->assertJsonPath('data.state', 'pending');
        $this->send($id, 'refuse')->assertCreated()->assertJsonPath('data.state', 'refused');
        $this->assertSame(4, count($this->read($id)['history']));
    }

    public function test_authorization_is_queued_originals_are_preserved_and_revoked_agreement_blocks_download(): void
    {
        Queue::fake();
        Storage::fake('rental_contracts');
        [$company,$actor,, $car,$id] = $this->fixture(true);
        $this->sheet($car->id);
        $this->send($id, 'request', $this->trip())->assertCreated();
        $this->send($id, 'approve', $this->insurance())->assertCreated()->assertJsonPath('data.can_issue', true);
        $this->send($id, 'issue')->assertCreated();
        Queue::assertPushed(RenderForeignAuthorization::class);
        $issue = $this->read($id)['authorization']['id'];
        $this->mock(ForeignAuthorization::class, function (MockInterface $m): void {
            $m->shouldReceive('pdf')->once()->andReturn('%PDF-fictif');
            $m->shouldReceive('docx')->once()->andReturn('PK-fictif');
        });
        Tenant::run($company->id, function () use ($company, $id, $issue): void {
            $job = new RenderForeignAuthorization($company->id, $id, $issue);
            $job->handle(app(ForeignAuthorization::class), app(ForeignTravel::class));
            $job->handle(app(ForeignAuthorization::class), app(ForeignTravel::class));
        });
        $this->getJson("/api/v1/reservations/$id/foreign-travel/$issue/pdf")->assertOk()->assertDownload();
        $this->getJson("/api/v1/reservations/$id/foreign-travel/$issue/docx")->assertOk()->assertDownload();
        $visibleEvents = json_encode(collect($this->getJson("/api/v1/reservations/$id")->assertOk()->json('events'))->filter(fn ($event) => str_starts_with($event['action'], 'foreign_'))->values()->all());
        $this->assertStringNotContainsString('request_hash', $visibleEvents);
        $this->assertStringNotContainsString('files', $visibleEvents);
        $this->assertStringNotContainsString('snapshot', $visibleEvents);
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'foreign_authorization_generated')->count());
        $this->send($id, 'cancel')->assertCreated()->assertJsonPath('data.state', 'cancelled');
        $this->getJson("/api/v1/reservations/$id/foreign-travel/$issue/pdf")->assertConflict();
        $this->assertSame(2, count(Storage::disk('rental_contracts')->allFiles()));
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'confirmee', 'frozen_total_centimes' => 135000]);
    }

    public function test_expiry_alerts_keep_current_deadline_when_a_future_renewal_exists(): void
    {
        [,,,$car,$id] = $this->fixture();
        $sheet = $this->sheet($car->id, null, '2026-10-01', '2026-10-24');
        $this->sheet($car->id, $sheet, '2026-10-26', '2026-11-30');
        $r = $this->getJson('/api/v1/dashboard/overview?tab=documents')->assertOk()->json();
        $row = collect($r['data'])->firstWhere('kind', 'circulation');
        $this->assertNotNull($row);
        $this->assertSame('2026-10-24', $row['date']);
        $this->assertSame('urgent', $row['state']);
        $this->assertSame($sheet, $this->read($id)['circulation']['current']['id']);
        $this->send($id, 'request', [...$this->trip(), 'ends_local' => '2026-10-25T09:00'])->assertCreated()->assertJsonPath('data.circulation_covers_trip', false);
        $this->send($id, 'approve', $this->insurance())->assertUnprocessable();
    }

    public function test_refusal_and_cancellation_require_reason_and_history_cannot_be_overwritten(): void
    {
        [,,,,$id] = $this->fixture();
        $this->send($id, 'request', $this->trip())->assertCreated();
        $this->send($id, 'refuse', ['reason' => ''])->assertUnprocessable();
        $this->send($id, 'cancel')->assertCreated();
        $this->send($id, 'approve', $this->insurance())->assertConflict();
        $this->assertSame(2, count($this->read($id)['history']));
    }

    public function test_word_uses_customer_language_and_escapes_document_fields(): void
    {
        $renderer = new ForeignAuthorization;
        $snapshot = ['reservation_id' => 999, 'context' => ['customer' => ['first_name' => 'Test & exemple', 'last_name' => 'Fictif', 'identity_number' => 'TEST123', 'licence_number' => 'TEST456', 'address' => '<adresse fictive>', 'language' => 'ar'], 'vehicle' => ['make' => 'Dacia', 'model' => 'Test', 'plate' => 'TEST-INT-999', 'vin' => null]], 'travel' => $this->trip(), 'insurance' => $this->insurance()];
        $bytes = $renderer->docx($snapshot, 'AUT-TEST-999');
        $path = tempnam(sys_get_temp_dir(), 'foreign-word-');
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('<w:bidi/>', $xml);
            $this->assertStringContainsString('Test &amp; exemple', $xml);
            $this->assertStringContainsString('&lt;adresse fictive&gt;', $xml);
            $this->assertStringNotContainsString('TargetMode="External"', $zip->getFromName('_rels/.rels'));
            $this->assertNotFalse(simplexml_load_string($xml));
            $zip->close();
        } finally {
            unlink($path);
        }
        if (getenv('FOREIGN_QA_OUTPUT') && config('rental_contracts.chrome')) {
            $dir = getenv('FOREIGN_QA_OUTPUT');
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            file_put_contents($dir.'/authorization-ar.docx', $bytes);
            $pdf = $renderer->pdf($snapshot, 'AUT-TEST-999');
            $this->assertStringStartsWith('%PDF-', $pdf);
            file_put_contents($dir.'/authorization-ar.pdf', $pdf);
        }
    }
}
