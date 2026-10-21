<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\RentalPayment;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-18T10:00:00Z'));
        $company = Company::factory()->create();
        [$member,$client,$car] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), Customer::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($member->user)->withSession(['company_id' => $company->id]);
        $id = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        return [$company, $member, $client, $car, $id];
    }

    private function quote(int $id, int $amount = 270000, ?int $expected = null)
    {
        return $this->postJson("/api/v1/reservations/$id/quote", ['total_centimes' => $amount, 'expected_quote_id' => $expected, 'reason' => 'Prix accepté par le client', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }

    private function pay(int $id, int $amount, string $method = 'especes', ?string $key = null)
    {
        return $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => $amount, 'method' => $method, 'received_on' => '2026-10-18', 'reference' => 'TEST-REF', 'receipt_attested' => true, 'request_key' => $key ?? (string) Str::uuid()]);
    }

    public function test_similar_payment_requires_current_review_and_replay_does_not_add_money(): void
    {
        [,,,,$id] = $this->fixture();
        $first = $this->pay($id, 90000)->assertCreated()->json('financial.payments.0.id');
        $this->pay($id, 90000)->assertConflict()->assertJsonPath('code', 'SIMILAR_PAYMENT')->assertJsonPath('similar_payment_ids', [$first]);
        $this->assertDatabaseCount('rental_payments', 1);
        $body = ['amount_centimes' => 90000, 'method' => 'especes', 'received_on' => '2026-10-18', 'receipt_attested' => true, 'distinct_payment_confirmed' => true, 'similar_payment_ids' => [$first], 'request_key' => (string) Str::uuid()];
        $url = "/api/v1/reservations/$id/payments";
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('financial.verified_centimes', 180000);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('replayed', true);
        $body['request_key'] = (string) Str::uuid();
        $this->postJson($url, $body)->assertConflict()->assertJsonPath('code', 'SIMILAR_PAYMENT');
        $this->assertDatabaseCount('rental_payments', 2);
        $this->pay($id, 90001)->assertCreated();
        $this->pay($id, 90000, 'virement')->assertCreated();
        $this->travel(11)->minutes();
        $this->pay($id, 90000)->assertCreated();
        $this->assertDatabaseCount('rental_payments', 5);
    }

    private function confirm(int $id, int $quote, ?string $key = null)
    {
        return $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => $key ?? (string) Str::uuid()]);
    }

    private function hold(int $id)
    {
        return $this->postJson("/api/v1/reservations/$id/hold", ['agreement_confirmed' => true, 'request_key' => (string) Str::uuid()]);
    }

    public function test_full_verified_payment_is_required_and_transfer_is_pending_until_responsible_verifies(): void
    {
        [,,,,$id] = $this->fixture();
        $quote = $this->quote($id)->assertCreated()->json('financial.quote_id');
        $this->hold($id)->assertCreated();
        $this->pay($id, 100000)->assertCreated()->assertJsonPath('financial.remaining_centimes', 170000);
        $payment = $this->pay($id, 170000, 'virement')->assertCreated()->assertJsonPath('financial.pending_centimes', 170000)->assertJsonPath('financial.remaining_centimes', 170000)->json('financial.payments.0.id');
        $this->confirm($id, $quote)->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_INCOMPLETE');
        $this->postJson("/api/v1/reservations/$id/payments/$payment/verify", ['reason' => 'Réception vérifiée sur relevé', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('financial.remaining_centimes', 0);
        $key = (string) Str::uuid();
        $this->confirm($id, $quote, $key)->assertCreated()->assertJsonPath('financial.frozen', true);
        $this->confirm($id, $quote, $key)->assertOk()->assertJsonPath('replayed', true);
        $this->travel(3)->hours();
        $this->artisan('reservations:expire-holds')->assertSuccessful();
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.status', 'confirmee')->assertJsonPath('data.blocks_vehicle', true)->assertJsonPath('data.hold_expired', false);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-22&days=7')->assertOk()->assertJsonPath('data.0.events.0.kind', 'confirmed');
        $this->quote($id, 200000, $quote)->assertUnprocessable()->assertJsonPath('code', 'PRICE_FROZEN');
        $this->assertDatabaseCount('reservation_confirmations', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'reservation_confirmed')->count());
    }

    public function test_employee_cannot_price_or_verify_but_can_record_pending_payment(): void
    {
        [$company,$responsible,,,$id] = $this->fixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->quote($id)->assertForbidden();
        $pid = $this->pay($id, 10000, 'virement')->assertCreated()->json('financial.payments.0.id');
        $this->postJson("/api/v1/reservations/$id/payments/$pid/verify", ['reason' => 'Reçu sur le compte', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('can_manage_price', false)->assertJsonPath('can_verify_transfer', false);
    }

    public function test_expiry_during_confirmation_releases_hold_but_retains_received_money(): void
    {
        [,,,,$id] = $this->fixture();
        $q = $this->quote($id)->json('financial.quote_id');
        $this->hold($id)->assertCreated();
        $this->travel(2)->hours();
        $this->pay($id, 270000)->assertCreated();
        $this->confirm($id, $q)->assertConflict()->assertJsonPath('code', 'HOLD_EXPIRED');
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'demande']);
        $this->assertDatabaseCount('rental_payments', 1);
        $this->hold($id)->assertCreated();
        $this->confirm($id, $q)->assertCreated();
    }

    public function test_competing_dossier_stays_and_actual_receipt_survives_conflict(): void
    {
        [,,$client,$car,$one] = $this->fixture();
        $two = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-22T10:00', 'ends_local' => '2026-10-25T10:00', 'request_key' => (string) Str::uuid()])->json('data.id');
        $q1 = $this->quote($one)->json('financial.quote_id');
        $q2 = $this->quote($two)->json('financial.quote_id');
        $this->pay($one, 270000)->assertCreated();
        $this->pay($two, 270000)->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T10:20:00Z'));
        $this->confirm($one, $q1)->assertCreated();
        $this->confirm($two, $q2)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->getJson("/api/v1/reservations/$two")->assertJsonPath('data.conflicts.0.status', 'confirmee')->assertJsonPath('financial.verified_centimes', 270000);
        $this->assertDatabaseCount('rental_payments', 2);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_payment_replay_and_payload_changes_are_safe_and_surplus_is_separate(): void
    {
        [,,,,$id] = $this->fixture();
        $this->quote($id, 10000)->assertCreated();
        $key = (string) Str::uuid();
        $this->pay($id, 15000, 'especes', $key)->assertCreated()->assertJsonPath('financial.surplus_centimes', 5000);
        $this->pay($id, 15000, 'especes', $key)->assertOk();
        $this->pay($id, 16000, 'especes', $key)->assertConflict();
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('rental_payment_verifications', 1);
        $this->pay($id, 0)->assertUnprocessable();
        $this->pay($id, -100)->assertUnprocessable();
    }

    public function test_stale_quote_and_injected_verification_and_cross_tenant_are_denied(): void
    {
        [,,,,$id] = $this->fixture();
        $old = $this->quote($id)->json('financial.quote_id');
        $this->quote($id, 280000, $old)->assertCreated();
        $this->quote($id, 250000, $old)->assertConflict();
        $this->pay($id, 280000)->assertCreated();
        $this->confirm($id, $old)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
        $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 10000, 'method' => 'virement', 'received_on' => '2026-10-18', 'reference' => 'proof', 'receipt_attested' => true, 'verified' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->fixture();
        $this->pay($id, 10000)->assertNotFound();
        $this->getJson("/api/v1/reservations/$id")->assertNotFound();
    }

    public function test_garage_blocks_confirmation_and_keeps_receipt(): void
    {
        [,,,$car,$id] = $this->fixture();
        $q = $this->quote($id)->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        $this->postJson("/api/v1/vehicles/$car->id/maintenance/files", ['mode' => 'garage', 'performed_on' => '2026-10-18', 'items' => [['kind' => 'repair', 'title' => 'Réparation', 'status' => 'pending', 'cost_status' => 'unknown']], 'paid_centimes' => 0, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T10:20:00Z'));
        $this->confirm($id, $q)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->assertDatabaseCount('rental_payments', 1);
    }

    public function test_confirmation_event_failure_rolls_back_confirmation_but_not_earlier_receipt(): void
    {
        [,,,,$id] = $this->fixture();
        $q = $this->quote($id)->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        DB::unprepared("CREATE FUNCTION test_reject_confirm() RETURNS trigger AS $$ BEGIN IF NEW.action='reservation_confirmed' THEN RAISE EXCEPTION 'test'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER test_confirm_fail BEFORE INSERT ON reservation_events FOR EACH ROW EXECUTE FUNCTION test_reject_confirm();");
        $this->confirm($id, $q)->assertServerError();
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'demande']);
        $this->assertDatabaseCount('reservation_confirmations', 0);
        $this->assertDatabaseCount('rental_payments', 1);
        DB::unprepared('DROP TRIGGER test_confirm_fail ON reservation_events; DROP FUNCTION test_reject_confirm();');
    }

    public function test_paid_ledger_is_immutable_at_database_level(): void
    {
        [,,,,$id] = $this->fixture();
        $this->pay($id, 10000)->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('rental_payments')->update(['amount_centimes' => 1]);
    }

    public function test_confirmed_price_is_frozen_at_database_level(): void
    {
        [,,,,$id] = $this->fixture();
        $q = $this->quote($id)->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        $this->confirm($id, $q)->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('reservations')->where('id', $id)->update(['frozen_total_centimes' => 1]);
    }

    public function test_expiry_while_checks_are_running_cannot_confirm_and_receipt_is_kept(): void
    {
        [,,,,$id] = $this->fixture();
        $q = $this->quote($id)->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        $this->hold($id)->assertCreated();
        $advance = fn () => $this->travel(2)->hours();
        $this->app->instance(RentalPayment::class, new class($advance) extends RentalPayment
        {
            public function __construct(private \Closure $advance) {}

            public function summary(Reservation $record): array
            {
                ($this->advance)();

                return parent::summary($record);
            }
        });
        $this->confirm($id, $q)->assertConflict()->assertJsonPath('code', 'HOLD_EXPIRED');
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('reservation_confirmations', 0);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'demande']);
    }

    public function test_walk_in_can_confirm_after_scheduled_start_without_changing_dates_price_or_receipt(): void
    {
        [,,,,$id] = $this->fixture();
        $quote = $this->quote($id)->assertCreated()->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        $before = DB::table('reservations')->where('id', $id)->first();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T10:20:00Z'));
        $key = (string) Str::uuid();
        $this->confirm($id, $quote, $key)->assertCreated()->assertJsonPath('financial.frozen', true);
        $this->confirm($id, $quote, $key)->assertOk()->assertJsonPath('replayed', true);
        $after = DB::table('reservations')->where('id', $id)->first();
        $this->assertSame($before->starts_at, $after->starts_at);
        $this->assertSame($before->ends_at, $after->ends_at);
        $this->assertSame('confirmee', $after->status);
        $this->assertSame(270000, $after->frozen_total_centimes);
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('reservation_confirmations', 1);
        $this->assertDatabaseCount('rental_pickups', 0);
    }

    public function test_confirmation_can_cross_midnight_but_not_the_end_of_the_agreed_rental_period(): void
    {
        [,,,,$id] = $this->fixture();
        $quote = $this->quote($id)->assertCreated()->json('financial.quote_id');
        $this->pay($id, 270000)->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-23T00:05:00Z'));
        $this->confirm($id, $quote)->assertCreated();
        [,,,,$ended] = $this->fixture();
        $otherQuote = $this->quote($ended)->assertCreated()->json('financial.quote_id');
        $this->pay($ended, 270000)->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-25T10:00:00Z'));
        $this->confirm($ended, $otherQuote)->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
        $this->assertDatabaseHas('reservations', ['id' => $ended, 'status' => 'demande']);
        $this->assertSame(1, DB::table('rental_payments')->where('reservation_id', $ended)->count());
        $this->assertSame(0, DB::table('reservation_confirmations')->where('reservation_id', $ended)->count());
    }

    public function test_pending_transfer_still_cannot_confirm_after_scheduled_start(): void
    {
        [,,,,$id] = $this->fixture();
        $quote = $this->quote($id)->assertCreated()->json('financial.quote_id');
        $this->pay($id, 270000, 'virement')->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T10:20:00Z'));
        $this->confirm($id, $quote)->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_INCOMPLETE');
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('reservation_confirmations', 0);
    }
}
