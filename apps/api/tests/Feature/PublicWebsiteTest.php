<?php

namespace Tests\Feature;

use App\Mail\WebsiteRequestReceived;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Reservations\VehiclePricing;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleFields;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private function photo(int $company, int $vehicle, int $actor, string $slot = 'cover', int $version = 1): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9L8AAAAASUVORK5CYII=');
        $path = "$vehicle/$slot-$version.png";
        Storage::disk('vehicle_photos')->put($path, $bytes);
        Tenant::run($company, fn () => VehiclePhoto::create(['vehicle_id' => $vehicle, 'actor_membership_id' => $actor, 'slot' => $slot, 'version' => $version, 'upload_key' => (string) Str::uuid(), 'disk' => 'vehicle_photos', 'path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]));
    }

    public function test_localized_vehicle_content_is_persisted_and_only_public_when_published(): void
    {
        [$co, $v] = $this->fixture();
        $description = ['fr' => 'Description test', 'en' => 'Test description', 'ar' => 'وصف تجريبي', 'es' => 'Descripción de prueba'];
        $equipment = [['fr' => 'Chargeur', 'en' => 'Charger', 'ar' => 'شاحن', 'es' => 'Cargador']];
        ksort($description); ksort($equipment[0]);
        Tenant::run($co->id, fn () => $v->update(['public_description' => $description, 'custom_equipment' => $equipment]));
        $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertOk()
            ->assertJsonPath('data.public_description', $description)->assertJsonPath('data.custom_equipment', $equipment);
        $this->getJson('/api/v1/public/vehicles')->assertOk()->assertJsonPath('data.0.public_description.en', 'Test description');
        $rules = (new \App\Http\Requests\SaveVehicleRequest)->rules();
        $rules = array_filter($rules, fn ($key) => str_starts_with($key, 'custom_equipment') || str_starts_with($key, 'public_description'), ARRAY_FILTER_USE_KEY);
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['public_description' => $description, 'custom_equipment' => $equipment], $rules)->passes());
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['custom_equipment' => [['fr' => 'Missing translations']]], $rules)->fails());
        Tenant::run($co->id, fn () => $v->update(['published' => false]));
        $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertNotFound();
    }

    public function test_publication_gallery_no_price_and_revocation(): void
    {
        [$co,$v,$actor] = $this->fixture();
        $this->getJson('/api/v1/public/vehicles')->assertJsonPath('data.0.id', $v->id);
        $this->photo($co->id, $v->id, $actor->id, 'front', 2);
        $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertOk()->assertJsonPath('data.gallery.0.slot', 'cover')->assertJsonPath('data.gallery.1.slot', 'front');
        $this->get('/api/v1/public/photo/'.$v->id.'/front')->assertOk();
        $this->get('/api/v1/public/photo/'.$v->id.'/invalid')->assertNotFound();
        $noPrice = Tenant::run($co->id, fn () => Vehicle::factory()->create(['published' => true]));
        $this->photo($co->id, $noPrice->id, $actor->id);
        $this->getJson('/api/v1/public/vehicles/'.$noPrice->id)->assertOk()->assertJsonPath('data.daily_centimes', null);
        Tenant::run($co->id, fn () => $v->update(['published' => false]));
        $ids = collect($this->getJson('/api/v1/public/vehicles')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($v->id));
        $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertNotFound();
        $this->get('/api/v1/public/photo/'.$v->id.'/front')->assertNotFound();
        $default = Tenant::run($co->id, fn () => Vehicle::factory()->create());
        $this->assertFalse($default->fresh()->published);
    }

    public function test_latest_slots_invalid_photos_tenant_and_dashboard_control(): void
    {
        [$co,$v,$actor] = $this->fixture();
        foreach (['front', 'rear', 'interior', 'boot', 'detail_1', 'detail_2', 'detail_3'] as $slot) {
            $this->photo($co->id, $v->id, $actor->id, $slot);
        }
        $this->photo($co->id, $v->id, $actor->id, 'front', 2);
        $data = $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertOk()->assertJsonCount(8, 'data.gallery')->json('data');
        $this->assertStringContainsString('v=2', $data['gallery'][1]['image']);
        Storage::disk('vehicle_photos')->put("$v->id/front-2.png", 'broken');
        $this->get('/api/v1/public/photo/'.$v->id.'/front')->assertNotFound();
        $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertJsonCount(7, 'data.gallery');
        $without = Tenant::run($co->id, fn () => Vehicle::factory()->create(['published' => true]));
        $this->getJson('/api/v1/public/vehicles/'.$without->id)->assertNotFound();
        $this->getJson('/api/v1/public/vehicles')->assertJsonCount(1, 'data');
        $payload = $v->only(VehicleFields::EDITABLE);
        $this->actingAs($actor->user)->withSession(['company_id' => $co->id])->putJson('/api/v1/vehicles/'.$v->id, [...$payload, 'published' => false, 'version' => $v->version])->assertOk()->assertJsonPath('data.published', false);
        $this->getJson('/api/v1/public/vehicles')->assertJsonCount(0, 'data');
        $event = DB::table('vehicle_events')->where('vehicle_id', $v->id)->latest('id')->first();
        $this->assertFalse(json_decode($event->after, true)['published']);
        Tenant::run($co->id, fn () => $v->refresh()->update(['published' => true]));
        $other = Company::factory()->create();
        config(['public_site.company_id' => $other->id]);
        $this->getJson('/api/v1/public/vehicles/'.$v->id)->assertNotFound();
        $this->get('/api/v1/public/photo/'.$v->id.'/cover')->assertNotFound();
    }

    private function fixture(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('vehicle_photos');
        $co = Company::factory()->create();
        [$v,$actor] = Tenant::run($co->id, fn () => [Vehicle::factory()->create(['published' => true]), CompanyMembership::factory()->responsible()->create()]);
        $this->photo($co->id, $v->id, $actor->id);
        config(['public_site.company_id' => $co->id, 'public_site.vehicle_ids' => [$v->id]]);
        Tenant::run($co->id, fn () => app(VehiclePricing::class)->setNormal($v->id, ['daily_centimes' => 40000, 'minimum_days' => 1, 'request_key' => (string) Str::uuid(), 'reason' => 'Test', 'expected_version' => null], $actor->id));
        $p = ['starts_local' => now('Africa/Casablanca')->addDays(10)->format('Y-m-d').'T10:00', 'ends_local' => now('Africa/Casablanca')->addDays(12)->format('Y-m-d').'T10:00'];
        $quote = $this->getJson('/api/v1/public/vehicles?'.http_build_query($p))->assertOk()->json('data.0.quote');

        return [$co, $v, $actor, [...$p, 'vehicle_id' => $v->id, 'first_name' => 'TEST Website', 'last_name' => 'Guest', 'phone' => '+212600000999', 'language' => 'es', 'pickup' => 'hotel', 'dropoff' => 'airport', 'pickup_detail' => 'TEST Hotel', 'quoted_centimes' => $quote['total_centimes'], 'quote_version' => $quote['version_id'], 'consent' => true, 'request_key' => (string) Str::uuid()]];
    }

    public function test_public_request_reaches_private_dossier_once_without_occupancy_or_payment(): void
    {
        [$co,$v,$actor,$p] = $this->fixture();
        $r = $this->postJson('/api/v1/public/requests', $p)->assertCreated();
        $this->postJson('/api/v1/public/requests', $p)->assertOk()->assertJson($r->json());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_events', 1);
        $this->assertDatabaseCount('customers', 1);
        $row = DB::table('reservations')->first();
        $this->assertSame('demande', $row->status);
        $this->assertNull($row->actor_membership_id);
        $this->assertSame('es', $row->language);
        $this->assertSame('website', $row->source);
        $this->assertSame(80000, json_decode($row->website_request, true)['quote']['total_centimes']);
        $this->assertSame(0, json_decode($row->website_request, true)['delivery_centimes']);
        $this->actingAs($actor->user)->withSession(['company_id' => $co->id])->getJson('/api/v1/reservations/'.$row->id)->assertOk()->assertJsonPath('data.blocks_vehicle', false)->assertJsonPath('events.0.actor', 'Site web')->assertJsonPath('data.website_request.pickup_detail', 'TEST Hotel');
    }

    public function test_no_public_private_data_and_unpublished_vehicle_rejected(): void
    {
        [$co,$v,$actor,$p] = $this->fixture();
        $data = $this->getJson('/api/v1/public/vehicles')->assertOk()->json('data.0');
        foreach (['plate', 'vin', 'company_id', 'current_odometer', 'path'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        Tenant::run($co->id, fn () => $v->update(['published' => false]));
        $this->postJson('/api/v1/public/requests', $p)->assertNotFound();
        $this->getJson('/api/v1/public/photo/'.$v->id)->assertNotFound();
    }

    public function test_tampered_price_tenant_and_missing_hotel_rejected(): void
    {
        [,,,$p] = $this->fixture();
        $this->postJson('/api/v1/public/requests', [...$p, 'quoted_centimes' => 1])->assertStatus(409);
        $this->postJson('/api/v1/public/requests', [...$p, 'company_id' => 99])->assertUnprocessable();
        $this->postJson('/api/v1/public/requests', [...$p, 'pickup_detail' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/public/requests', [...$p, 'website' => 'bot'])->assertUnprocessable();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_no_tenant_and_cross_tenant_fail_closed(): void
    {
        [,$v,,$p] = $this->fixture();
        $other = Company::factory()->create();
        config(['public_site.company_id' => $other->id]);
        $this->getJson('/api/v1/public/vehicles')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/public/requests', $p)->assertNotFound();
        config(['public_site.company_id' => 0]);
        $this->getJson('/api/v1/public/vehicles')->assertStatus(503);
    }

    public function test_four_languages_and_reused_key_payload_mismatch(): void
    {
        [,,,$p] = $this->fixture();
        foreach (['ar', 'en', 'es', 'fr'] as $l) {
            $this->postJson('/api/v1/public/requests', [...$p, 'language' => $l, 'request_key' => (string) Str::uuid()])->assertCreated();
        }
        $p['request_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/public/requests', $p)->assertCreated();
        $this->postJson('/api/v1/public/requests', [...$p, 'last_name' => 'Different'])->assertStatus(409);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_booking_receipt_tracking_and_preferences_are_tenant_safe(): void
    {
        [$co,$v,,$p] = $this->fixture();
        Mail::fake();
        config(['public_site.url' => 'https://agency.example']);
        $p = [...$p, 'email' => 'booking-test@example.test', 'pickup' => 'city', 'notes' => 'TEST child seat', 'payment_method' => 'transfer'];
        $created = $this->postJson('/api/v1/public/requests', $p)->assertCreated()->assertJsonPath('mail_status', 'preview')->json();
        $this->postJson('/api/v1/public/requests', $p)->assertOk()->assertJsonPath('tracking_token', $created['tracking_token']);
        Mail::assertSentCount(1);
        Mail::assertSent(WebsiteRequestReceived::class, fn ($mail) => $mail->reference === $created['reference'] && str_contains($mail->trackingUrl, $created['tracking_token']) && str_contains($mail->render(), $created['reference']));
        $lookup = ['reference' => $created['reference'], 'token' => $created['tracking_token']];
        $data = $this->postJson('/api/v1/public/requests/lookup', $lookup)->assertOk()->assertJsonPath('status', 'demande')->assertJsonPath('pickup', 'city')->json();
        foreach (['email', 'phone', 'contact', 'company_id', 'request_key', 'notes', 'customer_id'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->postJson('/api/v1/public/requests/lookup', [...$lookup, 'token' => str_repeat('0', 64)])->assertNotFound();
        $row = DB::table('reservations')->first();
        $details = json_decode($row->website_request, true);
        $this->assertSame('transfer', $details['payment_method']);
        $this->assertSame('TEST child seat', $details['notes']);
        DB::table('reservations')->where('id', $row->id)->update(['status' => 'annulee']);
        $this->postJson('/api/v1/public/requests/lookup', $lookup)->assertOk()->assertJsonPath('status', 'annulee');
        $other = Company::factory()->create();
        config(['public_site.company_id' => $other->id]);
        $this->postJson('/api/v1/public/requests/lookup', $lookup)->assertNotFound();
    }

    public function test_mail_failure_does_not_lose_or_duplicate_request(): void
    {
        [,,,$p] = $this->fixture();
        config(['public_site.url' => 'https://agency.example']);
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('TEST transport failure'));
        $p = [...$p, 'email' => 'booking-test@example.test'];
        $r = $this->postJson('/api/v1/public/requests',$p)->assertCreated()->assertJsonPath('mail_status','failed')->json();
        $this->postJson('/api/v1/public/requests',$p)->assertOk()->assertJsonPath('reference',$r['reference']);
        $this->assertDatabaseCount('reservations',1);
        $this->assertDatabaseCount('reservation_events',1);
    }
}
