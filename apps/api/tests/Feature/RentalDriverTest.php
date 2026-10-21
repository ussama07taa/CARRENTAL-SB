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
use Tests\TestCase;

class RentalDriverTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $confirmed = true): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
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


    private function driver(int $id, string $action, array $extra = [])
    {
        $revision = $this->getJson("/api/v1/reservations/$id/drivers")->assertOk()->json('data.revision');
        return $this->postJson("/api/v1/reservations/$id/drivers", ['action' => $action, 'expected_revision' => $revision, 'reason' => 'Conducteur fictif autorisé', 'request_key' => (string) Str::uuid(), ...$extra]);
    }

    private function person(int $company): Customer
    {
        return Tenant::run($company, fn () => Customer::factory()->create(['identity_type' => 'cin', 'identity_number' => 'SECOND123', 'licence_number' => 'SECONDLIC', 'licence_expires_on' => '2030-01-01']));
    }

    private function newPersonPayload(): array
    {
        return ['first_name'=>'Nouveau','last_name'=>'Conducteur fictif','phone'=>'0612345678','identity_type'=>'cin','identity_number'=>'NEW123','licence_number'=>'PERMIS123','licence_expires_on'=>'2032-12-31','language'=>'fr','creation_key'=>(string) Str::uuid(),'expected_revision'=>null,'reason'=>'Ajout fictif dans le dossier'];
    }

    public function test_inline_creation_is_atomic_replayable_and_available_to_employee(): void
    {
        [$company,,,, $id]=$this->fixture();
        $employee=Tenant::run($company->id,fn()=>CompanyMembership::factory()->create(['is_primary_responsible'=>false]));
        $this->actingAs($employee->user);
        $input=$this->newPersonPayload();$count=DB::table('customers')->count();
        $url="/api/v1/reservations/$id/drivers/new";
        $person=$this->postJson($url,$input)->assertCreated()->assertJsonPath('data.verified',false)->assertJsonPath('data.customer.phone','+212612345678')->json('data.customer.id');
        $this->postJson($url,$input)->assertOk()->assertJsonPath('replayed',true)->assertJsonPath('data.customer.id',$person);
        $this->assertDatabaseCount('customers',$count+1);
        $this->assertDatabaseCount('rental_driver_entries',1);
        $this->postJson($url,[...$input,'first_name'=>'Changed'])->assertConflict();
        $this->assertDatabaseHas('customer_events',['customer_id'=>$person,'actor_membership_id'=>$employee->id,'action'=>'created']);
    }

    public function test_inline_invalid_duplicate_or_stale_submission_leaves_no_extra_person(): void
    {
        [$company,,,, $id]=$this->fixture();$input=$this->newPersonPayload();$url="/api/v1/reservations/$id/drivers/new";
        $count=DB::table('customers')->count();
        $this->postJson($url,[...$input,'licence_number'=>''])->assertUnprocessable();
        $this->postJson($url,[...$input,'phone'=>'invalid'])->assertUnprocessable();
        $this->postJson($url,[...$input,'licence_expires_on'=>'2026-10-21'])->assertUnprocessable()->assertJsonValidationErrors('licence_expires_on');
        $this->postJson($url,[...$input,'expected_revision'=>12345])->assertConflict();
        $this->postJson($url,[...$input,'identity_number'=>'TEST123'])->assertConflict()->assertJsonPath('code','IDENTITY_ALREADY_USED');
        $this->assertDatabaseCount('customers',$count);
        $person=$this->person($company->id);
        $this->postJson($url,[...$input,'phone'=>$person->phone])->assertConflict()->assertJsonPath('code','POSSIBLE_DUPLICATE');
        $this->assertDatabaseCount('customers',$count+1);
        $this->postJson($url,[...$input,'phone'=>$person->phone,'acknowledge_duplicates'=>true])->assertCreated();
    }

    public function test_inline_creation_rolls_back_when_driver_event_fails_and_rejects_other_tenant(): void
    {
        [$company,,,, $id]=$this->fixture();$count=DB::table('customers')->count();
        $real=app(\App\Support\Reservations\RentalContract::class);
        $mock=\Mockery::mock(\App\Support\Reservations\RentalContract::class)->makePartial();
        $mock->shouldReceive('event')->once()->andThrow(new \RuntimeException('Simulated event failure'));
        $this->app->instance(\App\Support\Reservations\RentalContract::class,$mock);
        $this->postJson("/api/v1/reservations/$id/drivers/new",$this->newPersonPayload())->assertStatus(500);
        $this->assertDatabaseCount('customers',$count);
        $this->assertDatabaseCount('rental_driver_entries',0);
        $this->app->instance(\App\Support\Reservations\RentalContract::class,$real);
        $this->fixture(false);$count=DB::table('customers')->count();
        $this->postJson("/api/v1/reservations/$id/drivers/new",$this->newPersonPayload())->assertNotFound();
        $this->assertDatabaseCount('customers',$count);
    }

    public function test_employee_and_manager_can_assign_verify_and_remove_with_audit_and_replay(): void
    {
        [$company, $manager, , , $id] = $this->fixture();
        $person = $this->person($company->id);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $request = ['action' => 'assigned', 'customer_id' => $person->id, 'expected_revision' => null, 'reason' => 'Ajout fictif', 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/drivers", $request)->assertCreated()->assertJsonPath('data.verified', false);
        $this->postJson("/api/v1/reservations/$id/drivers", $request)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/reservations/$id/drivers", [...$request, 'reason' => 'Autre motif'])->assertConflict();
        $fingerprint = $this->getJson("/api/v1/reservations/$id/drivers")->json('data.fingerprint');
        $this->driver($id, 'verified', ['fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true])->assertCreated()->assertJsonPath('data.verified', true);
        $this->assertDatabaseHas('reservation_events', ['reservation_id' => $id, 'actor_membership_id' => $employee->id, 'action' => 'additional_driver_verified']);
        $this->actingAs($manager->user);
        $this->driver($id, 'removed')->assertCreated()->assertJsonPath('data.customer', null)->assertJsonPath('data.verified', true);
        $this->assertDatabaseCount('rental_driver_entries', 3);
    }

    public function test_invalid_driver_stale_document_and_stale_revision_are_rejected(): void
    {
        [$company, , $main, , $id] = $this->fixture();
        $this->driver($id, 'assigned', ['customer_id' => $main->id])->assertUnprocessable();
        $person = $this->person($company->id);
        $fingerprint = $this->driver($id, 'assigned', ['customer_id' => $person->id])->assertCreated()->json('data.fingerprint');
        $this->driver($id, 'verified', ['fingerprint' => $fingerprint, 'identity_original' => false, 'licence_original' => true])->assertUnprocessable();
        $this->driver($id, 'removed', ['expected_revision' => null])->assertConflict();
        Tenant::run($company->id, fn () => $person->update(['licence_number' => 'CHANGED']));
        $this->driver($id, 'verified', ['fingerprint' => $fingerprint, 'identity_original' => true, 'licence_original' => true])->assertConflict();
        Tenant::run($company->id, fn () => $person->update(['licence_expires_on' => '2020-01-01']));
        $new = $this->getJson("/api/v1/reservations/$id/drivers")->json('data.fingerprint');
        $this->driver($id, 'verified', ['fingerprint' => $new, 'identity_original' => true, 'licence_original' => true])->assertUnprocessable();
    }

    public function test_verification_invalidates_and_contract_snapshot_preserves_original_and_no_driver_fingerprint(): void
    {
        [$company, , , , $id] = $this->fixture();
        $snapshot = fn () => Tenant::run($company->id, fn () => app(\App\Support\Reservations\RentalContract::class)->snapshot(\App\Models\Reservation::findOrFail($id)));
        $original = $snapshot();
        $this->assertArrayNotHasKey('additional_driver', $original);
        $person = $this->person($company->id);
        $fp = $this->driver($id, 'assigned', ['customer_id' => $person->id])->json('data.fingerprint');
        $this->driver($id, 'verified', ['fingerprint' => $fp, 'identity_original' => true, 'licence_original' => true])->assertCreated();
        $signedSnapshot = $snapshot();
        $this->assertSame($person->id, $signedSnapshot['additional_driver']['id']);
        $this->assertNotEquals($original, $signedSnapshot);
        $this->assertSame($original['reservation'], $signedSnapshot['reservation']);
        Tenant::run($company->id, fn () => $person->update(['identity_number' => 'NEWIDENTITY']));
        $this->getJson("/api/v1/reservations/$id/drivers")->assertJsonPath('data.verified', false)->assertJsonMissingPath('data.snapshot');
        $this->getJson("/api/v1/reservations/$id/departure")->assertJsonFragment(['Vérifier les originaux du conducteur supplémentaire avant de préparer le contrat.']);
        $this->assertSame('SECOND123', $signedSnapshot['additional_driver']['identity_number']);
        $this->driver($id, 'removed')->assertCreated();
        $this->assertNotEquals($original, $snapshot());
    }

    public function test_tenant_isolation_and_no_changes_after_departure_or_before_confirmation(): void
    {
        [$company, , , , $id] = $this->fixture();
        $other = Company::factory()->create();
        $foreign = $this->person($other->id);
        $this->driver($id, 'assigned', ['customer_id' => $foreign->id])->assertNotFound();
        $person = $this->person($company->id);
        DB::table('reservations')->where('id', $id)->update(['status' => 'en_location']);
        $this->driver($id, 'assigned', ['customer_id' => $person->id])->assertUnprocessable();
        [,,,, $unconfirmed] = $this->fixture(false);
        $this->getJson("/api/v1/reservations/$id/drivers")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/drivers", ['action' => 'removed', 'expected_revision' => null, 'reason' => 'Test isolement', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->driver($unconfirmed, 'removed')->assertUnprocessable();
    }

    public function test_driver_history_cannot_be_rewritten(): void
    {
        [$company, , , , $id] = $this->fixture();
        $this->driver($id, 'assigned', ['customer_id' => $this->person($company->id)->id])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('rental_driver_entries')->update(['reason' => 'Rewrite']);
    }
}
