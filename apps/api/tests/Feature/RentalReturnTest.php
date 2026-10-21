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
use App\Support\Reservations\RequestPeriod;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RentalReturnTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_home_followups_keep_readiness_settlement_and_pending_receipts_separate(): void
    {
        [$company, $owner, , , $id] = $this->approvedFixture(false, false, true);
        $receipt = $this->getJson("/api/v1/reservations/$id/departure")->json('data.caution.receipts.0.id');
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        Storage::fake('rental_returns');
        $return = $this->receive($id, $this->returnInput($id))->assertCreated()->json('data.return.id');
        $this->getJson('/api/v1/dashboard/overview')->assertOk()
            ->assertJsonPath('followups.counts.readiness', 1)->assertJsonPath('followups.counts.settlement', 1)
            ->assertJsonPath('followups.rows.0.href', "/reservations?dossier=$id&tab=operations&return_id=$return");
        $signature = 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/signature.png')));
        $inspection = $this->inspectReturn($id, $this->control($id, ['signature_state' => 'signed', 'signature_reason' => null, 'client_signature' => $signature, 'agency_signature' => $signature]))->assertCreated()->json('data.inspection.id');
        config(['rental_returns.readiness_approvers' => 'responsible']);
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Prête après contrôle TEST', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/dashboard/overview?task_group=settlement')->assertOk()->assertJsonPath('followups.counts.readiness', 0)->assertJsonPath('followups.counts.settlement', 1);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 200000, 'method' => 'especes']))->assertCreated();
        $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('followups.counts.settlement', 0);
        $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 1000, 'method' => 'virement', 'reference' => 'Test attente', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/dashboard/overview?task_group=pending')->assertOk()->assertJsonPath('followups.counts.pending', 1)->assertJsonPath('followups.rows.0.amount_centimes', 1000)->assertJsonPath('followups.rows.0.href', "/reservations?dossier=$id&tab=payments&payment_view=receipts");
        $this->getJson('/api/v1/dashboard/overview?task_group=wrong')->assertUnprocessable();
        $other = Company::factory()->create();
        $otherOwner = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherOwner->user)->withSession(['company_id' => $other->id]);
        $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonPath('followups.counts', ['readiness' => 0, 'settlement' => 0, 'pending' => 0]);
    }

    public function test_damage_ticket_is_linked_to_return_and_blocks_readiness_until_review(): void
    {
        [$company, $owner, , $car, $id] = $this->approvedFixture(false, false, true);
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        Storage::fake('rental_returns');
        $return = $this->receive($id, $this->returnInput($id))->assertCreated()->json('data.return.id');
        $inspection = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $vehicleId = DB::table('rental_returns')->where('id', $return)->value('vehicle_id');
        $ticket = $this->postJson('/api/v1/damages', [
            'kind'=>'create', 'vehicle_id'=>$vehicleId, 'inspection_id'=>$inspection,
            'title'=>'Rayure porte droite', 'zone'=>'Côté droit', 'description'=>'Rayure nouvelle à examiner',
            'occurred_local'=>now('Africa/Casablanca')->subMinute()->format('Y-m-d\TH:i'),
            'owner_membership_id'=>$owner->id, 'follow_up_on'=>now()->toDateString(),
            'next_action'=>'Vérifier la sécurité', 'reason'=>'Dommage constaté au retour', 'request_key'=>(string) Str::uuid(),
        ])->assertCreated()->json('id');
        $this->getJson('/api/v1/damages/'.$ticket)->assertOk()
            ->assertJsonPath('observations.0.reservation_id', $id)
            ->assertJsonPath('observations.0.inspection_id', $inspection);
        $this->assertContains($vehicleId, Tenant::run($company->id, fn()=>app(\App\Support\Reservations\RentalReturn::class)->unreadyVehicles()));
        config(['rental_returns.readiness_approvers'=>'responsible']);
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['inspection_id'=>$inspection,'vehicle_ready'=>true,'reason'=>'Vérification test','request_key'=>(string) Str::uuid()])->assertConflict();
        $this->postJson('/api/v1/damages/'.$ticket, ['kind'=>'followup','expected_version'=>1,'owner_membership_id'=>$owner->id,'follow_up_on'=>now()->addDay()->toDateString(),'next_action'=>'Prévoir réparation carrosserie','mobility'=>'usable','repair'=>'deferred','checked'=>true,'reason'=>'Rayure contrôlée sans anomalie bloquante','request_key'=>(string)Str::uuid()])->assertCreated();
        $this->assertNotContains($vehicleId, Tenant::run($company->id, fn()=>app(\App\Support\Vehicles\DamageTickets::class)->blockers()));
        $this->assertDatabaseHas('reservations',['id'=>$id,'status'=>'retournee']);
        $this->assertDatabaseCount('damage_observations',1);
        $frozen=DB::table('reservations')->where('id',$id)->value('frozen_total_centimes');
        foreach ([50000,30000] as $amount) {
            $this->postJson('/api/v1/damages/'.$ticket,['kind'=>'decision','expected_version'=>DB::table('damage_tickets')->where('id',$ticket)->value('version'),'reservation_id'=>$id,'responsibility'=>'customer','amount_centimes'=>$amount,'evidence_reference'=>'Photos du contrôle et accord documenté','checked'=>true,'reason'=>'Décision justifiée sur les photos','request_key'=>(string)Str::uuid()])->assertCreated();
        }
        $summary=$this->getJson("/api/v1/reservations/$id/settlement")->assertOk()->json('data');
        $this->assertSame(30000,$summary['damage_remaining_centimes']);
        $this->postJson("/api/v1/reservations/$id/settlement",['kind'=>'deposit_allocate','scope'=>'damage','deposit_receipt_id'=>$summary['deposit']['receipts'][0]['id'],'amount_centimes'=>20000,'expected_revision'=>$summary['revision'],'occurred_on'=>now()->toDateString(),'reason'=>'Retenue approuvée avec photos','checked'=>true,'request_key'=>(string)Str::uuid()])->assertCreated();
        $this->postJson('/api/v1/damages/'.$ticket,['kind'=>'decision','expected_version'=>DB::table('damage_tickets')->where('id',$ticket)->value('version'),'reservation_id'=>$id,'responsibility'=>'customer','amount_centimes'=>10000,'evidence_reference'=>'Correction à examiner','checked'=>true,'reason'=>'Correction après affectation','request_key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->assertSame($frozen,DB::table('reservations')->where('id',$id)->value('frozen_total_centimes'));
        $this->getJson("/api/v1/reservations/$id/settlement")->assertOk()->assertJsonPath('data.damage_remaining_centimes',10000);

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

    private function approvedFixture(bool $demo = false, bool $durationReference = false, bool $withDeposit = false, string $depositMethod = 'especes'): array
    {
        $fixture = $this->readyFile();
        [$company,$actor,,$car,$id] = $fixture;
        Storage::fake('vehicle_documents');
        Tenant::run($company->id, function () use ($actor, $car): void {
            foreach (['registration', 'insurance', 'inspection', 'circulation'] as $kind) {
                $path = $kind.'.png';
                Storage::disk('vehicle_documents')->put($path, 'vehicle document fixture');
                VehicleDocument::create(['vehicle_id' => $car->id, 'actor_membership_id' => $actor->id, 'kind' => $kind, 'version' => 1, 'change_kind' => 'initial', 'effective_on' => $kind === 'registration' ? null : '2026-01-01', 'expires_on' => $kind === 'registration' ? null : '2030-01-01', 'disk' => 'vehicle_documents', 'path' => $path, 'mime' => 'image/png', 'size' => 24, 'sha256' => hash('sha256', 'vehicle document fixture'), 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'test')]);
            }
        });
        config(['rental_contracts.demo_only' => $demo, 'rental_contracts.model_version' => $demo ? 'demo-1' : 'approved-test-fixture']);
        if ($withDeposit) {
            $this->postJson("/api/v1/reservations/$id/departure", ['action' => 'caution_received', 'amount_centimes' => 200000, 'method' => $depositMethod, 'reference' => 'Caution TEST', 'received_on' => now()->toDateString(), 'reason' => 'Caution TEST reçue', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        }
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

    private function outFile(): array
    {
        $fixture = $this->approvedFixture();
        $id = $fixture[4];
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        Storage::fake('rental_returns');

        return $fixture;
    }

    private function returnInput(int $id): array
    {
        return ['returned_local' => now('Africa/Casablanca')->format('Y-m-d\TH:i'), 'vehicle_received' => true, 'reason' => 'Retour physique FICTIF TEST', 'request_key' => (string) Str::uuid()];
    }

    private function receive(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/return", $input);
    }

    private function control(int $id, array $changes = []): array
    {
        $data = $this->getJson("/api/v1/reservations/$id/return")->assertOk()->json('data');

        return [...['expected_inspection_id' => $data['inspection']['id'] ?? null, 'vehicle_version' => $data['vehicle']['version'], 'kilometers' => 20200, 'fuel_eighths' => 4, 'condition' => 'bon', 'notes' => null, 'reason' => 'Contrôle retour FICTIF TEST', 'signature_state' => 'pending', 'signature_reason' => 'Les parties signeront après revue', 'checked' => true, 'photos' => [UploadedFile::fake()->createWithContent('return.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII='))], 'captions' => ['Photo retour TEST'], 'request_key' => (string) Str::uuid()], ...$changes];
    }

    private function inspectReturn(int $id, array $input): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/return/inspection", $input);
    }

    private function settlementInput(int $id, string $kind, string $scope, array $changes = []): array
    {
        return [...['kind' => $kind, 'scope' => $scope, 'amount_centimes' => 1000, 'expected_revision' => $this->getJson("/api/v1/reservations/$id/settlement")->json('data.revision'), 'occurred_on' => now()->toDateString(), 'reason' => 'Règlement FICTIF TEST documenté', 'checked' => true, 'request_key' => (string) Str::uuid()], ...$changes];
    }

    public function test_pending_fuel_transfer_can_be_voided_without_erasing_history_and_replaced_with_cash(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id))->assertCreated();
        $payment = $this->postJson("/api/v1/reservations/$id/return/fuel/payments", ['amount_centimes' => 8050, 'method' => 'virement', 'received_on' => now()->toDateString(), 'reference' => 'Virement TEST jamais reçu', 'reason' => 'Annonce FICTIVE', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.payments.0.id');
        $input = $this->settlementInput($id, 'payment_void', 'fuel', ['payment_id' => $payment, 'no_funds_received' => true]);
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertCreated()->assertJsonPath('data.fuel.pending_centimes', 0)->assertJsonPath('data.fuel.payments.0.status', 'void');
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments/$payment/verify", ['reason' => 'Essai interdit TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", ['amount_centimes' => 8050, 'method' => 'especes', 'received_on' => now()->toDateString(), 'reason' => 'Espèces reçues TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.remaining_centimes', 0);
        $this->assertDatabaseCount('rental_fuel_payments', 2);
        $this->assertDatabaseCount('rental_settlement_entries', 1);
    }

    public function test_fuel_correction_and_approved_refund_have_separate_decision_and_execution(): void
    {
        [$company,$owner,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id));
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", ['amount_centimes' => 8050, 'method' => 'especes', 'received_on' => now()->toDateString(), 'reason' => 'Espèces TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()]);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'fuel_amount', 'fuel', ['amount_centimes' => 5000, 'agreement_confirmed' => true]))->assertCreated()->assertJsonPath('data.fuel.surplus_centimes', 3050);
        $approval = $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_approved', 'fuel', ['amount_centimes' => 3050]))->assertCreated()->assertJsonPath('data.fuel.verified_centimes', 8050)->json('data.refund_approvals.0.id');
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'fuel_amount', 'fuel', ['amount_centimes' => 100, 'agreement_confirmed' => true]))->assertForbidden();
        $input = $this->settlementInput($id, 'refund_paid', 'fuel', ['source_id' => $approval, 'amount_centimes' => 3050, 'method' => 'especes']);
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertCreated()->assertJsonPath('data.refund_due_centimes', 0)->assertJsonPath('data.fuel.surplus_centimes', 0);
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertOk();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_paid', 'fuel', ['source_id' => $approval, 'amount_centimes' => 1, 'method' => 'especes']))->assertUnprocessable();
        $this->assertDatabaseHas('rental_fuel_charges', ['reservation_id' => $id, 'amount_centimes' => 8050]);
        $this->actingAs($owner->user);
        $this->expectException(QueryException::class);
        DB::table('rental_settlement_entries')->update(['reason' => 'Écrasement interdit']);
    }

    public function test_deposit_partial_returns_and_allocations_cannot_exceed_received_funds(): void
    {
        [$company,$owner,,,$id] = $this->approvedFixture(false, false, true);
        $receipt = $this->getJson("/api/v1/reservations/$id/departure")->json('data.caution.receipts.0.id');
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        Storage::fake('rental_returns');
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'deposit_allocate', 'fuel', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 8050]))->assertCreated()->assertJsonPath('data.fuel.remaining_centimes', 0)->assertJsonPath('data.deposit.held_centimes', 191950);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 100000, 'method' => 'especes']))->assertCreated()->assertJsonPath('data.deposit.held_centimes', 91950);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 91951, 'method' => 'especes']))->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 91950, 'method' => 'especes']))->assertCreated()->assertJsonPath('data.deposit.held_centimes', 0);
        $this->assertDatabaseCount('rental_fuel_payments', 0);
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseHas('reservation_departure_entries', ['id' => $receipt, 'amount_centimes' => 200000]);
    }

    public function test_card_deposit_requires_release_and_employee_cannot_release_after_damaged_return(): void
    {
        [$company,$owner,,,$id] = $this->approvedFixture(false, false, true, 'carte_bloquee');
        $receipt = $this->getJson("/api/v1/reservations/$id/departure")->json('data.caution.receipts.0.id');
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        Storage::fake('rental_returns');
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id, ['condition' => 'dommages_existants', 'notes' => 'Marque fictive à examiner']))->assertCreated();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $input = $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 200000, 'method' => 'carte_bloquee', 'reference' => 'Levée TEST']);
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertForbidden();
        $this->actingAs($owner->user);
        $this->postJson("/api/v1/reservations/$id/settlement", [...$input, 'method' => 'especes'])->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertCreated()->assertJsonPath('data.deposit.held_centimes', 0);
        $this->assertDatabaseCount('rental_payments', 1);
        $other = Company::factory()->create();
        $actor = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($actor->user)->withSession(['company_id' => $other->id]);
        $this->getJson("/api/v1/reservations/$id/settlement")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/settlement", [...$input, 'request_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_unexecuted_refund_approval_can_be_withdrawn_without_erasing_any_paid_refund(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'rent_total', 'rent', ['amount_centimes' => 260000, 'agreement_confirmed' => true]))->assertCreated();
        $approval = $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_approved', 'rent', ['amount_centimes' => 10000]))->assertCreated()->json('data.refund_approvals.0.id');
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_paid', 'rent', ['source_id' => $approval, 'amount_centimes' => 3000, 'method' => 'especes']))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_cancelled', 'rent', ['source_id' => $approval, 'amount_centimes' => 7001]))->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_cancelled', 'rent', ['source_id' => $approval, 'amount_centimes' => 7000]))->assertCreated()->assertJsonPath('data.refund_due_centimes', 0)->assertJsonPath('data.rent.verified_centimes', 267000);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'refund_paid', 'rent', ['source_id' => $approval, 'amount_centimes' => 1, 'method' => 'especes']))->assertUnprocessable();
        $this->assertDatabaseHas('reservations', ['id' => $id, 'frozen_total_centimes' => 270000]);
        $this->getJson("/api/v1/reservations/$id/settlement")->assertOk()->assertJsonPath('data.rent.initial_total_centimes', 270000)->assertJsonPath('data.rent.total_centimes', 260000)->assertJsonPath('data.rent.regularized', true);
    }

    public function test_cancellation_preserves_confirmed_price_documents_and_money_without_automatic_refund(): void
    {
        [,,,,$id] = $this->approvedFixture();
        $before = DB::table('reservations')->where('id', $id)->first();
        $files = DB::table('rental_contract_files')->get()->toJson();
        $input = ['final_total_centimes' => 0, 'expected_revision' => null, 'reason' => 'Annulation FICTIVE avant remise, accord client TEST', 'agreement_confirmed' => true, 'no_vehicle_handed_over' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/cancellation", $input)->assertCreated()->assertJsonPath('data.rent.verified_centimes', 270000)->assertJsonPath('data.rent.surplus_centimes', 270000)->assertJsonPath('data.refund_due_centimes', 0);
        $this->postJson("/api/v1/reservations/$id/cancellation", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/reservations/$id/cancellation", [...$input, 'final_total_centimes' => 100])->assertConflict();
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.status', 'annulee')->assertJsonPath('data.blocks_vehicle', false);
        $after = DB::table('reservations')->where('id', $id)->first();
        $before->status = 'annulee';
        $this->assertEquals($before, $after);
        $this->assertSame($files, DB::table('rental_contract_files')->get()->toJson());
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('rental_cancellations', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'reservation_cancelled')->count());
        $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => 1, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
    }

    public function test_cancellation_is_for_responsible_before_pickup_only_and_keeps_future_availability(): void
    {
        [$company,$owner,,,$id] = $this->approvedFixture();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $input = ['final_total_centimes' => 10000, 'expected_revision' => null, 'reason' => 'Accord FICTIF d’annulation', 'agreement_confirmed' => true, 'no_vehicle_handed_over' => true, 'request_key' => (string) Str::uuid()];
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/cancellation", $input)->assertForbidden();
        $this->actingAs($owner->user);
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/cancellation", $input)->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
        $this->assertDatabaseCount('rental_cancellations', 0);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'en_location']);
    }

    public function test_pending_deposit_is_visible_and_late_reception_can_be_verified_after_pickup(): void
    {
        [,,,,$id] = $this->approvedFixture(false, false, true, 'virement');
        $receipt = $this->getJson("/api/v1/reservations/$id/departure")->json('data.caution.receipts.0.id');
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.account.pending_centimes', 200000)->assertJsonPath('data.account.settled', false);
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        $input = ['action' => 'caution_verified', 'receipt_id' => $receipt, 'reason' => 'Réception bancaire tardive FICTIVE vérifiée', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/departure", $input)->assertCreated();
        $this->postJson("/api/v1/reservations/$id/departure", $input)->assertOk();
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.account.pending_centimes', 0)->assertJsonPath('data.account.deposit_held_centimes', 200000)->assertJsonPath('data.status', 'en_location');
    }

    public function test_settlement_requires_explicit_amount_and_request_key_reuse_in_another_file_is_conflict(): void
    {
        [,, $customer,$car,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $input = $this->settlementInput($id, 'rent_total', 'rent', ['amount_centimes' => 260000, 'agreement_confirmed' => true]);
        $missingAmount = $input;
        unset($missingAmount['amount_centimes']);
        $this->postJson("/api/v1/reservations/$id/settlement", $missingAmount)->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/settlement", $input)->assertCreated();
        $next = $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $car->id, 'starts_local' => '2026-10-28T10:00', 'ends_local' => '2026-10-29T10:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/reservations/$next/settlement", $input)->assertConflict();
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.account.credit_centimes', 10000)->assertJsonPath('data.account.settled', false);
        $this->assertDatabaseCount('rental_settlement_entries', 1);
    }

    private function replacementInput(int $id, int $vehicleId, int $difference = 30000): array
    {
        $summary = $this->getJson("/api/v1/reservations/$id/vehicle-swaps")->assertOk()->json('data');

        return ['vehicle_id' => $vehicleId, 'return_id' => $summary['return_id'] ?? 1, 'inspection_id' => $summary['inspection_id'] ?? 1, 'expected_swap_id' => $summary['active_swap_id'], 'expected_total_centimes' => $summary['financial']['total_centimes'], 'assigned_local' => $summary['server_now_local'], 'difference_centimes' => $difference, 'reason' => 'Remplacement FICTIF accepté avec le client', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    private function replacementContractFixture(int $company, int $actor, int $id): void
    {
        Tenant::run($company, function () use ($company, $actor, $id): void {
            $contracts = app(RentalContract::class);
            $snapshot = $contracts->snapshot(Reservation::findOrFail($id));
            $previous = DB::table('rental_contract_versions')->where('reservation_id', $id)->latest('id')->first();
            $version = DB::table('rental_contract_versions')->insertGetId(['company_id' => $company, 'reservation_id' => $id, 'actor_membership_id' => $actor, 'version' => $previous->version + 1, 'previous_id' => $previous->id, 'snapshot' => json_encode($snapshot), 'fingerprint' => $contracts->fingerprint($snapshot), 'reason' => 'Signed replacement fixture, not a production contract', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'replacement'), 'created_at' => now()]);
            foreach (['original', 'signed'] as $kind) {
                $bytes = '%PDF-1.4 replacement fixture '.$kind;
                $path = $version.'-'.$kind.'.pdf';
                Storage::disk('rental_contracts')->put($path, $bytes);
                $file = DB::table('rental_contract_files')->insertGetId(['company_id' => $company, 'reservation_id' => $id, 'contract_id' => $version, 'actor_membership_id' => $actor, 'kind' => $kind, 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'reason' => 'Fixture fictive', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'replacement'), 'created_at' => now()]);
                if ($kind === 'signed') {
                    DB::table('rental_contract_verifications')->insert(['company_id' => $company, 'reservation_id' => $id, 'contract_id' => $version, 'file_id' => $file, 'actor_membership_id' => $actor, 'reason' => 'Fixture vérifiée', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'replacement'), 'created_at' => now()]);
                }
            }
        });
    }

    public function test_vehicle_swap_preserves_both_legs_and_requires_new_inspection_contract_and_verified_supplement(): void
    {
        [$company, $actor, , $first, $id] = $this->outFile();
        config(['rental_returns.readiness_approvers' => 'responsible']);
        $original = DB::table('reservations')->where('id', $id)->first();
        $firstPickup = DB::table('rental_pickups')->where('reservation_id', $id)->first();
        $originalContract = DB::table('rental_contract_versions')->where('reservation_id', $id)->first();
        $second = Tenant::run($company->id, fn () => Vehicle::factory()->create(['current_odometer' => 150000, 'current_odometer_date' => '2026-10-22']));
        Tenant::run($company->id, function () use ($first, $second): void {
            foreach (VehicleDocument::where('vehicle_id', $first->id)->get() as $document) {
                $copy = $document->replicate();
                $copy->vehicle_id = $second->id;
                $copy->request_key = (string) Str::uuid();
                $copy->save();
            }
        });
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $this->replacementInput($id, $second->id))->assertUnprocessable();
        $firstReturn = $this->receive($id, $this->returnInput($id))->assertCreated()->json('data.return.id');
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $this->replacementInput($id, $second->id))->assertUnprocessable();
        $oldControl = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'fuel_amount', 'fuel', ['amount_centimes' => 7000, 'agreement_confirmed' => true]))->assertCreated();
        $input = $this->replacementInput($id, $second->id);
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $input)->assertCreated()->assertJsonPath('data.status', 'confirmee')->assertJsonPath('data.financial.total_centimes', 300000);
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", [...$input, 'reason' => 'Autre accord'])->assertConflict();
        $this->assertDatabaseCount('rental_vehicle_swaps', 1);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'vehicle_id' => $second->id, 'starts_at' => $original->starts_at, 'ends_at' => $original->ends_at, 'frozen_total_centimes' => 270000]);
        $this->assertSame((array) $firstPickup, (array) DB::table('rental_pickups')->where('id', $firstPickup->id)->first());
        $this->assertSame((array) $originalContract, (array) DB::table('rental_contract_versions')->where('id', $originalContract->id)->first());
        $this->getJson("/api/v1/reservations/$id/departure/inspection")->assertOk()->assertJsonPath('data.latest', null);
        $this->getJson("/api/v1/reservations/$id/return")->assertOk()->assertJsonPath('data.return', null)->assertJsonPath('data.departure', null);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->assertJsonPath('data.stale', true);
        $this->postJson("/api/v1/reservations/$id/cancellation", ['final_total_centimes' => 0, 'expected_revision' => null, 'reason' => 'Ne pas effacer le départ', 'agreement_confirmed' => true, 'no_vehicle_handed_over' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
        $this->getJson("/api/v1/reservations/$id/return?return_id=$firstReturn")->assertOk()->assertJsonPath('data.vehicle.id', $first->id)->assertJsonPath('data.inspection.id', $oldControl);
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['return_id' => $firstReturn, 'inspection_id' => $oldControl, 'vehicle_ready' => true, 'reason' => 'Première voiture prête TEST', 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.vehicle_ready', true);
        $payment = $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 30000, 'method' => 'virement', 'received_on' => now()->toDateString(), 'reference' => 'Supplément fictif', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.payments.0.id');
        $checks = collect($this->getJson("/api/v1/reservations/$id/pickup")->json('data.checks'))->keyBy('key');
        $this->assertFalse($checks['payment']['ok']);
        $this->assertFalse($checks['inspection']['ok']);
        $this->assertFalse($checks['contract']['ok']);
        $this->postJson("/api/v1/reservations/$id/payments/$payment/verify", ['reason' => 'Supplément reçu TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->assertFalse(collect($this->getJson("/api/v1/reservations/$id/pickup")->json('data.checks'))->firstWhere('key', 'payment')['ok']);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", ['amount_centimes' => 7000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'reason' => 'Carburant première voiture FICTIF soldé', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->inspect($id, $this->payload($id, ['inspected_on' => '2026-10-22', 'fuel_eighths' => 7]))->assertCreated()->assertJsonPath('data.latest.kilometers', 150000);
        $this->replacementContractFixture($company->id, $actor->id, $id);
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        $secondPickup = DB::table('rental_pickups')->where('reservation_id', $id)->latest('id')->first();
        $this->assertNotSame($firstPickup->id, $secondPickup->id);
        $this->receive($id, $this->returnInput($id))->assertCreated()->assertJsonPath('data.departure.kilometers', 150000)->assertJsonPath('data.departure.fuel_eighths', 7);
        $this->inspectReturn($id, $this->control($id, ['kilometers' => 150050, 'fuel_eighths' => 5]))->assertCreated()->assertJsonPath('data.inspection.snapshot.distance_km', 50);
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id))->assertCreated()->assertJsonPath('data.charge.amount_centimes', 15050);
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.account.remaining_centimes', 8050);
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'rent_total', 'rent', ['amount_centimes' => 280000, 'agreement_confirmed' => true]))->assertCreated()->assertJsonPath('data.rent.total_centimes', 280000);
        $this->getJson("/api/v1/reservations/$id/vehicle-swaps")->assertOk()->assertJsonCount(2, 'data.legs')->assertJsonPath('data.legs.0.vehicle.id', $first->id)->assertJsonPath('data.legs.1.vehicle.id', $second->id);
        $this->assertDatabaseCount('rental_returns', 2);
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $this->replacementInput($id, $first->id, -10050))->assertCreated()->assertJsonPath('data.financial.total_centimes', 269950);
        $html = Tenant::run($company->id, fn () => app(ContractPdf::class)->html(json_decode(json_encode(app(RentalContract::class)->snapshot(Reservation::findOrFail($id))), true), 'QA-AVENANT'));
        $this->assertStringContainsString('AVENANT - REMPLACEMENT DU VÉHICULE', $html);
        $this->assertStringContainsString('-100,50 DH', $html);
        $this->getJson("/api/v1/reservations/$id/departure/inspection")->assertOk()->assertJsonPath('data.latest', null);
        $this->assertDatabaseCount('rental_vehicle_swaps', 2);
        $this->assertDatabaseCount('rental_pickups', 2);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-22&days=4')->assertOk()->assertJsonFragment(['key' => 'past-leg-'.$firstPickup->id])->assertJsonFragment(['key' => 'past-leg-'.$secondPickup->id]);
    }

    public function test_vehicle_swap_checks_remaining_period_conflicts_permissions_and_available_deposit(): void
    {
        [$company, $actor, $client, $first, $id] = $this->approvedFixture(false, false, true);
        $this->pickup($id, $this->pickupInput($id))->assertCreated();
        Storage::fake('rental_returns');
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $this->inspectReturn($id, $this->control($id))->assertCreated();
        $second = Tenant::run($company->id, fn () => Vehicle::factory()->create());
        $input = $this->replacementInput($id, $second->id, -10000);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $input)->assertForbidden();
        $this->actingAs($actor->user);
        $conflict = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $second->id, 'starts_local' => '2026-10-22T13:00', 'ends_local' => '2026-10-24T13:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/reservations/$conflict/hold", ['agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $input)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->assertDatabaseCount('rental_vehicle_swaps', 0);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'vehicle_id' => $first->id, 'status' => 'retournee']);
        $this->postJson("/api/v1/reservations/$conflict/cancellation", ['final_total_centimes' => 0, 'expected_revision' => null, 'reason' => 'Libérer TEST', 'agreement_confirmed' => true, 'no_vehicle_handed_over' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $receipt = DB::table('reservation_departure_entries')->where('reservation_id', $id)->where('action', 'caution_received')->value('id');
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'deposit_return', 'deposit', ['deposit_receipt_id' => $receipt, 'amount_centimes' => 200000, 'method' => 'especes']))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $input)->assertCreated()->assertJsonPath('data.financial.total_centimes', 260000);
        $this->postJson("/api/v1/reservations/$id/departure", ['action' => 'caution_set', 'amount_centimes' => 200000, 'expected_terms_id' => DB::table('reservation_departure_entries')->where('reservation_id', $id)->whereIn('action', ['caution_set', 'caution_waived'])->max('id'), 'reason' => 'Caution seconde voiture TEST', 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.caution.covered', false)->assertJsonPath('data.caution.verified_centimes', 0);
        $this->assertDatabaseCount('rental_pickups', 1);
        $otherCompany = Company::factory()->create();
        $otherActor = Tenant::run($otherCompany->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherActor->user)->withSession(['company_id' => $otherCompany->id]);
        $this->getJson("/api/v1/reservations/$id/vehicle-swaps")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $input)->assertNotFound();
    }

    public function test_swap_occupies_only_remaining_period_and_database_rejects_unapproved_vehicle_changes(): void
    {
        [$company, , $client, $first, $id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $this->inspectReturn($id, $this->control($id))->assertCreated();
        $second = Tenant::run($company->id, fn () => Vehicle::factory()->create());
        try {
            DB::transaction(fn () => DB::table('reservations')->where('company_id', $company->id)->where('id', $id)->update(['vehicle_id' => $second->id]));
            $this->fail('A vehicle change requires the saved swap agreement.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('accord conservé', $e->getMessage());
        }
        $this->assertDatabaseHas('reservations', ['id' => $id, 'vehicle_id' => $first->id, 'status' => 'retournee']);
        $current = CarbonImmutable::now();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00Z'));
        $past = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $second->id, 'starts_local' => '2026-10-22T07:00', 'ends_local' => '2026-10-22T08:05', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $quote = $this->postJson("/api/v1/reservations/$past/quote", ['total_centimes' => 10000, 'reason' => 'Accord antérieur FICTIF', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
        $this->postJson("/api/v1/reservations/$past/payments", ['amount_centimes' => 10000, 'method' => 'especes', 'received_on' => '2026-10-21', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/reservations/$past/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->travelTo($current);
        $this->postJson("/api/v1/reservations/$id/vehicle-swaps", $this->replacementInput($id, $second->id, 0))->assertCreated();
        $this->assertDatabaseHas('reservations', ['id' => $past, 'status' => 'confirmee', 'vehicle_id' => $second->id]);
        $swap = DB::table('rental_vehicle_swaps')->where('reservation_id', $id)->first();
        $this->assertNotNull($swap);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('rental_vehicle_swaps')->where('id', $swap->id)->update(['difference_centimes' => 1]));
    }

    private function extensionAgreement(int $id, int $vehicle, string $end = '2026-10-26T10:00'): array
    {
        $normal = $this->getJson("/api/v1/vehicles/$vehicle/pricing")->assertOk()->json('data');
        if ($normal['daily_centimes'] === null) {
            $this->postJson("/api/v1/vehicles/$vehicle/pricing", ['daily_centimes' => 45000, 'minimum_days' => 3, 'expected_version' => $normal['version_id'], 'reason' => 'Tarif FICTIF prolongation', 'request_key' => (string) Str::uuid()])->assertCreated();
        }
        $before = $this->getJson("/api/v1/reservations/$id/extensions")->assertOk()->json('data');
        $quote = $this->getJson("/api/v1/reservations/$id/extensions/quote?ends_local=".urlencode($end))->assertOk()->json('data');
        $input = ['ends_local' => $end, 'expected_latest_id' => $before['latest_id'], 'fingerprint' => $quote['fingerprint'], 'amount_centimes' => $quote['pricing']['total_centimes'], 'reason' => 'Période supplémentaire acceptée FICTIVE TEST', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];

        return [$this->postJson("/api/v1/reservations/$id/extensions", $input)->assertCreated()->json('data.history.0'), $input, $quote];
    }

    public function test_demo_extension_issue_requires_full_payment_and_keeps_current_contract_until_activation(): void
    {
        [, , , $vehicle, $id] = $this->readyFile();
        config(['rental_contracts.demo_only' => true]);
        $initial = $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->json('data');
        $original = $this->postJson("/api/v1/reservations/$id/departure/contract", ['fingerprint' => $initial['fingerprint'], 'expected_latest_id' => null, 'reason' => 'Contrat initial FICTIF', 'reviewed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.latest_id');
        [$agreement] = $this->extensionAgreement($id, $vehicle->id);
        $a = $agreement['id'];
        $summary = $this->getJson("/api/v1/reservations/$id/departure/contract?extension_id=$a")->assertOk()->json('data');
        $input = ['extension_id' => $a, 'fingerprint' => $summary['fingerprint'], 'expected_latest_id' => $summary['latest_version_id'], 'reason' => 'Avenant FICTIF accepté', 'reviewed' => true, 'request_key' => (string) Str::uuid()];
        $payment = ['amount_centimes' => 20000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/payments", $payment)->assertCreated();
        $this->postJson("/api/v1/reservations/$id/departure/contract", $input)->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/payments", [...$payment, 'amount_centimes' => 25000, 'request_key' => (string) Str::uuid()])->assertCreated();
        $version = $this->postJson("/api/v1/reservations/$id/departure/contract?extension_id=$a", $input)->assertCreated()->json('data.latest_id');
        $this->postJson("/api/v1/reservations/$id/departure/contract?extension_id=$a", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('rental_contract_versions', ['id' => $version, 'previous_id' => $original, 'extension_id' => $a, 'version' => 2]);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->assertJsonPath('data.latest_id', $original)->assertJsonPath('data.stale', false);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'active_extension_id' => null, 'frozen_total_centimes' => 270000]);
    }

    private function signExtension(int $company, int $id, int $agreement): array
    {
        $url = "/api/v1/reservations/$id/departure/contract?extension_id=$agreement";
        $summary = $this->getJson($url)->assertOk()->json('data');
        $contract = Tenant::run($company, function () use ($company, $id, $agreement, $summary): int {
            $previous = DB::table('rental_contract_versions')->where('company_id', $company)->where('reservation_id', $id)->latest('version')->first();

            return DB::table('rental_contract_versions')->insertGetId(['company_id' => $company, 'reservation_id' => $id, 'extension_id' => $agreement, 'actor_membership_id' => $previous->actor_membership_id, 'previous_id' => $previous->id, 'version' => $previous->version + 1, 'snapshot' => json_encode($summary['preview']), 'fingerprint' => $summary['fingerprint'], 'reason' => 'Approved fictional model fixture', 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'extension-fixture'), 'created_at' => now()]);
        });
        $pdf = \Mockery::mock(ContractPdf::class);
        $pdf->shouldReceive('render')->once()->andReturn("%PDF-1.4\nFICTIVE EXTENSION\n%%EOF");
        (new RenderRentalContract($company, $contract))->handle($pdf, app(RentalContract::class));
        $file = $this->postJson("/api/v1/reservations/$id/departure/contract/$contract/signed?extension_id=$agreement", ['file' => UploadedFile::fake()->createWithContent('signed.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jw1sAAAAASUVORK5CYII=')), 'expected_signed_id' => null, 'reason' => 'Copie signée FICTIVE TEST', 'same_version' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.versions.0.files.1.id');
        $this->postJson("/api/v1/reservations/$id/departure/contract/$contract/verify?extension_id=$agreement", ['file_id' => $file, 'reason' => 'Signatures FICTIVES contrôlées', 'signatures_checked' => true, 'request_key' => (string) Str::uuid()])->assertCreated();

        return ['contract_id' => $contract, 'signed_file_id' => $file, 'reason' => 'Activation FICTIVE après revue de l’accord', 'checked' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_extension_activates_only_after_verified_full_funds_and_signed_agreement_and_preserves_original(): void
    {
        [$company, $actor, , $vehicle, $id] = $this->outFile();
        $before = DB::table('reservations')->where('id', $id)->first();
        $pickup = DB::table('rental_pickups')->where('reservation_id', $id)->first();
        $oldContract = DB::table('rental_contract_versions')->where('reservation_id', $id)->first();
        [$agreement, $input, $quote] = $this->extensionAgreement($id, $vehicle->id);
        $a = $agreement['id'];
        $this->assertSame(45000, $agreement['amount_centimes']);
        $this->assertSame(2, $quote['pricing']['lines'][0]['half_day_units']);
        $this->postJson("/api/v1/reservations/$id/extensions", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'ends_at' => $before->ends_at, 'frozen_total_centimes' => 270000]);
        $url = "/api/v1/reservations/$id/extensions/$a/activate";
        $this->postJson($url, ['contract_id' => $oldContract->id, 'signed_file_id' => $pickup->signed_file_id, 'reason' => 'Ne pas activer sans règlement', 'checked' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $payment = $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 45000, 'method' => 'virement', 'received_on' => now()->toDateString(), 'reference' => 'Prolongation FICTIVE', 'note' => 'Fonds de prolongation TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.payments.0.id');
        $this->getJson("/api/v1/reservations/$id/extensions")->assertOk()->assertJsonPath('data.history.0.funds.remaining_centimes', 45000)->assertJsonPath('data.history.0.funds.pending_centimes', 45000);
        $this->postJson($url, ['contract_id' => $oldContract->id, 'signed_file_id' => $pickup->signed_file_id, 'reason' => 'Virement pas encore reçu', 'checked' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/payments/$payment/verify", ['reason' => 'Virement FICTIF reçu', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $activation = $this->signExtension($company->id, $id, $a);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->assertJsonPath('data.latest_id', $oldContract->id)->assertJsonPath('data.signed_verified', true);
        $this->postJson($url, $activation)->assertCreated()->assertJsonPath('data.history.0.state', 'active')->assertJsonPath('data.financial.total_centimes', 315000)->assertJsonPath('data.financial.remaining_centimes', 0);
        $this->postJson($url, $activation)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->assertJsonPath('data.latest_id', $activation['contract_id'])->assertJsonPath('data.signed_verified', true)->assertJsonPath('data.stale', false);
        $this->assertSame((array) $oldContract, (array) DB::table('rental_contract_versions')->where('id', $oldContract->id)->first());
        $this->assertSame((array) $pickup, (array) DB::table('rental_pickups')->where('id', $pickup->id)->first());
        $after = DB::table('reservations')->where('id', $id)->first();
        $this->assertSame($before->starts_at, $after->starts_at);
        $this->assertSame($before->frozen_total_centimes, $after->frozen_total_centimes);
        $this->assertSame($before->preparation_minutes, $after->preparation_minutes);
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.ends_local', '2026-10-26T10:00')->assertJsonPath('data.account.remaining_centimes', 0);
        $this->assertDatabaseCount('rental_extension_activations', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('reservation_id', $id)->where('action', 'extension_activated')->count());
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/settlement", $this->settlementInput($id, 'rent_total', 'rent', ['amount_centimes' => 300000, 'agreement_confirmed' => true]))->assertCreated()->assertJsonPath('data.rent.total_centimes', 300000);
    }

    public function test_successive_extensions_add_separate_paid_lines_and_preserve_each_agreement(): void
    {
        [$company, , , $vehicle, $id] = $this->outFile();
        foreach (['2026-10-26T10:00', '2026-10-27T10:00'] as $index => $end) {
            [$agreement] = $this->extensionAgreement($id, $vehicle->id, $end);
            $this->getJson("/api/v1/reservations/$id/extensions")->assertOk()->assertJsonPath('data.history.0.funds.remaining_centimes', 45000);
            $paymentInput = ['amount_centimes' => 45000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
            $paymentResponse = $this->postJson("/api/v1/reservations/$id/payments", $paymentInput);
            if ($index > 0) {
                $paymentResponse->assertConflict()->assertJsonPath('code', 'SIMILAR_PAYMENT');
                $paymentResponse = $this->postJson("/api/v1/reservations/$id/payments", [...$paymentInput, 'distinct_payment_confirmed' => true, 'similar_payment_ids' => $paymentResponse->json('similar_payment_ids')]);
            }
            $paymentResponse->assertCreated();
            $activation = $this->signExtension($company->id, $id, $agreement['id']);
            $this->postJson("/api/v1/reservations/$id/extensions/{$agreement['id']}/activate", $activation)->assertCreated()->assertJsonPath('data.financial.total_centimes', 270000 + ($index + 1) * 45000)->assertJsonPath('data.financial.remaining_centimes', 0);
            $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->assertJsonPath('data.latest_id', $activation['contract_id'])->assertJsonPath('data.stale', false);
        }
        $this->assertDatabaseCount('rental_extension_activations', 2);
        $this->assertDatabaseCount('rental_extension_agreements', 2);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'frozen_total_centimes' => 270000]);
    }

    public function test_extension_conflict_after_funds_received_keeps_receipt_and_initial_period(): void
    {
        [$company, , $client, $vehicle, $id] = $this->outFile();
        $before = DB::table('reservations')->where('id', $id)->first();
        [$agreement] = $this->extensionAgreement($id, $vehicle->id);
        $a = $agreement['id'];
        $this->postJson("/api/v1/reservations/$id/payments", ['amount_centimes' => 45000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'note' => 'Réception réelle FICTIVE indépendante', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $activation = $this->signExtension($company->id, $id, $a);
        $other = $this->postJson('/api/v1/reservations', ['customer_id' => $client->id, 'vehicle_id' => $vehicle->id, 'starts_local' => '2026-10-25T12:00', 'ends_local' => '2026-10-26T12:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $quote = $this->postJson("/api/v1/reservations/$other/quote", ['total_centimes' => 10000, 'reason' => 'Accord distinct TEST', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
        $this->postJson("/api/v1/reservations/$other/payments", ['amount_centimes' => 10000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/reservations/$other/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/reservations/$id/extensions/$a/activate", $activation)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->assertDatabaseHas('reservations', ['id' => $id, 'ends_at' => $before->ends_at, 'blocked_until' => $before->blocked_until, 'active_extension_id' => null]);
        $this->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('financial.verified_centimes', 315000)->assertJsonPath('financial.surplus_centimes', 45000);
        $this->assertDatabaseCount('rental_extension_activations', 0);
    }

    public function test_extension_permissions_price_exception_and_unapproved_date_mutation(): void
    {
        [$company, $actor, , $vehicle, $id] = $this->outFile();
        [$agreement, $input] = $this->extensionAgreement($id, $vehicle->id);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/extensions", $input)->assertForbidden();
        $this->getJson("/api/v1/reservations/$id/extensions/quote?ends_local=2026-10-26T10:00")->assertForbidden();
        $this->postJson("/api/v1/reservations/$id/extensions/{$agreement['id']}/activate", ['contract_id' => 1, 'signed_file_id' => 1, 'reason' => 'Pas autorisé TEST', 'checked' => true, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($actor->user);
        $this->postJson("/api/v1/reservations/$id/extensions", [...$input, 'request_key' => (string) Str::uuid(), 'expected_latest_id' => $agreement['id'], 'amount_centimes' => 10000])->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/extensions", [...$input, 'request_key' => (string) Str::uuid(), 'expected_latest_id' => $agreement['id'], 'amount_centimes' => 10000, 'exception_reason' => 'Prix exceptionnel accepté FICTIF'])->assertCreated();
        $this->getJson("/api/v1/reservations/$id/extensions")->assertOk()->assertJsonPath('data.history.1.state', 'superseded');
        try {
            DB::transaction(fn () => DB::table('reservations')->where('id', $id)->update(['ends_at' => '2026-10-28T12:00Z', 'blocked_until' => '2026-10-28T14:00Z']));
            $this->fail('A date change requires a signed funded activation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('activation conservée', $e->getMessage());
        }
        $other = Company::factory()->create();
        $outsider = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($outsider->user)->withSession(['company_id' => $other->id]);
        $this->getJson("/api/v1/reservations/$id/extensions")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/extensions", $input)->assertNotFound();
    }

    private function fuelInput(int $id): array
    {
        return ['inspection_id' => $this->getJson("/api/v1/reservations/$id/return")->json('data.inspection.id'), 'amount_centimes' => 8050, 'ticket_reference' => 'Ticket station FICTIF 123', 'reason' => 'Montant du ticket accepté par le client TEST', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_fuel_agreement_and_partial_receipts_preserve_rent_contract_and_readiness(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $inspection = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Disponible TEST', 'request_key' => (string) Str::uuid()])->assertCreated();
        $before = DB::table('reservations')->where('id', $id)->first();
        $contracts = DB::table('rental_contract_versions')->get()->toJson();
        $rent = $this->getJson("/api/v1/reservations/$id/return")->json('data.financial');
        $input = $this->fuelInput($id);
        $this->postJson("/api/v1/reservations/$id/return/fuel", $input)->assertCreated()->assertJsonPath('data.remaining_centimes', 8050)->assertJsonPath('data.verified_centimes', 0);
        $this->postJson("/api/v1/reservations/$id/return/fuel", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/reservations/$id/return/fuel", [...$input, 'amount_centimes' => 100])->assertConflict();
        $this->postJson("/api/v1/reservations/$id/return/fuel", [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $payment = ['amount_centimes' => 3000, 'method' => 'especes', 'received_on' => now()->toDateString(), 'reason' => 'Paiement partiel TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", $payment)->assertCreated()->assertJsonPath('data.remaining_centimes', 5050);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", $payment)->assertOk();
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", [...$payment, 'amount_centimes' => 5051, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", [...$payment, 'amount_centimes' => 5050, 'method' => 'carte', 'reference' => 'Reçu carte TEST', 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.remaining_centimes', 0);
        $this->assertDatabaseCount('rental_fuel_charges', 1);
        $this->assertDatabaseCount('rental_fuel_payments', 2);
        $this->assertEquals($before, DB::table('reservations')->where('id', $id)->first());
        $this->assertSame($contracts, DB::table('rental_contract_versions')->get()->toJson());
        $summary = $this->getJson("/api/v1/reservations/$id/return")->assertOk()->assertJsonPath('data.vehicle_ready', true);
        $this->assertSame($rent, $summary->json('data.financial'));
        $this->assertDatabaseCount('rental_payments', 1);
        $this->assertDatabaseCount('rental_fuel_verifications', 2);
        foreach (['rental_fuel_charges', 'rental_fuel_payments', 'rental_fuel_verifications'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->update(['reason' => 'Overwrite TEST']));
                $this->fail('Les écritures carburant doivent rester immuables.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('P0001', $e->getMessage());
            }
        }
    }

    public function test_fuel_transfer_is_pending_until_responsible_verifies_once(): void
    {
        [$company,$owner,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id))->assertCreated();
        $input = ['amount_centimes' => 8050, 'method' => 'virement', 'received_on' => now()->toDateString(), 'reference' => 'Virement TEST', 'reason' => 'Virement annoncé TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
        $payment = $this->postJson("/api/v1/reservations/$id/return/fuel/payments", $input)->assertCreated()->assertJsonPath('data.pending_centimes', 8050)->assertJsonPath('data.verified_centimes', 0)->assertJsonPath('data.remaining_centimes', 8050)->json('data.payments.0.id');
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments", [...$input, 'method' => 'especes', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $check = ['reason' => 'Crédit effectif sur compte vérifié TEST', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()];
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments/$payment/verify", $check)->assertForbidden();
        $this->actingAs($owner->user);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments/$payment/verify", $check)->assertCreated()->assertJsonPath('data.remaining_centimes', 0)->assertJsonPath('data.pending_centimes', 0);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments/$payment/verify", $check)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/reservations/$id/return/fuel/payments/$payment/verify", [...$check, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('rental_fuel_verifications', 1);
    }

    public function test_fuel_requires_responsible_agreement_valid_amount_and_current_fuel_shortfall(): void
    {
        [$company,$owner,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $input = $this->fuelInput($id);
        foreach (['agreement_confirmed' => false, 'amount_centimes' => '80.5', 'ticket_reference' => '', 'company_id' => $company->id] as $field => $value) {
            $this->postJson("/api/v1/reservations/$id/return/fuel", [...$input, $field => $value])->assertUnprocessable();
        }
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->postJson("/api/v1/reservations/$id/return/fuel", $input)->assertForbidden();
        $this->getJson("/api/v1/reservations/$id/return/fuel")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->actingAs($owner->user);
        $this->inspectReturn($id, $this->control($id, ['fuel_eighths' => 6]))->assertCreated();
        $this->postJson("/api/v1/reservations/$id/return/fuel", $input)->assertConflict();
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInput($id))->assertUnprocessable();
        $this->assertDatabaseCount('rental_fuel_charges', 0);
    }

    public function test_fuel_ticket_is_private_exact_and_cross_tenant_access_is_rejected(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $ticket = file_get_contents(__DIR__.'/../Fixtures/signature.png');
        $input = [...$this->fuelInput($id), 'ticket' => UploadedFile::fake()->createWithContent('ticket.png', $ticket)];
        $charge = $this->post("/api/v1/reservations/$id/return/fuel", $input, ['Accept' => 'application/json'])->assertCreated()->json('data.charge.id');
        $this->post("/api/v1/reservations/$id/return/fuel", $input, ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($ticket, $this->get("/api/v1/reservations/$id/return/fuel/$charge/content")->assertOk()->streamedContent());
        $other = Company::factory()->create();
        $owner = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($owner->user)->withSession(['company_id' => $other->id]);
        $this->getJson("/api/v1/reservations/$id/return/fuel")->assertNotFound();
        $this->getJson("/api/v1/reservations/$id/return/fuel/$charge/content")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/return/fuel", $this->fuelInputWithoutLookup())->assertNotFound();
        $this->withSession(['company_id' => null])->getJson("/api/v1/reservations/$id/return/fuel")->assertForbidden();
    }

    private function fuelInputWithoutLookup(): array
    {
        return ['inspection_id' => 1, 'amount_centimes' => 100, 'reason' => 'TEST inter agence', 'ticket_reference' => 'Ticket TEST', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];
    }

    public function test_failed_fuel_ticket_storage_does_not_create_charge_or_undo_return(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id));
        $this->inspectReturn($id, $this->control($id));
        $input = [...$this->fuelInput($id), 'ticket' => UploadedFile::fake()->createWithContent('ticket.png', file_get_contents(__DIR__.'/../Fixtures/signature.png'))];
        $mock = \Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('put')->andThrow(new \RuntimeException('Disk unavailable TEST'));
        $mock->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('rental_returns')->andReturn($mock);
        $this->post("/api/v1/reservations/$id/return/fuel", $input, ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseCount('rental_fuel_charges', 0);
        $this->assertDatabaseCount('rental_returns', 1);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'retournee']);
    }

    public function test_return_is_atomic_replayable_and_preserves_prices_dates_pickup_and_documents(): void
    {
        [, , , $car, $id] = $this->outFile();
        $before = DB::table('reservations')->where('id', $id)->first();
        $pickup = DB::table('rental_pickups')->first();
        $files = DB::table('rental_contract_files')->get()->toArray();
        $input = $this->returnInput($id);
        $this->receive($id, $input)->assertCreated()->assertJsonPath('data.status', 'retournee')->assertJsonPath('data.vehicle_ready', false)->assertJsonPath('data.inspection', null);
        $this->receive($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->receive($id, [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
        $this->receive($id, [...$input, 'reason' => 'Different'])->assertConflict();
        $this->assertDatabaseCount('rental_returns', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('action', 'rental_returned')->count());
        $after = DB::table('reservations')->where('id', $id)->first();
        foreach (['starts_at', 'ends_at', 'blocked_until', 'preparation_minutes', 'frozen_total_centimes'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
        $this->assertEquals($pickup, DB::table('rental_pickups')->first());
        $this->assertEquals($files, DB::table('rental_contract_files')->get()->toArray());
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.status', 'retournee')->assertJsonPath('data.blocks_vehicle', false);
        $this->getJson('/api/v1/reservations/planning?from=2026-10-19&days=14')->assertJsonPath('data.0.out_now', false)->assertJsonPath('data.0.return_pending', true)->assertJsonPath('data.0.events.0.kind', 'returned');
        $this->postJson("/api/v1/reservations/$id/confirm", ['quote_id' => DB::table('reservation_quotes')->where('reservation_id', $id)->max('id'), 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'UNTRANSITIONABLE');
    }

    public function test_pending_money_never_blocks_receiving_return_and_no_fee_or_refund_is_created(): void
    {
        [$company,,,,$id] = $this->outFile();
        $summary = Tenant::run($company->id, fn () => app(RentalPayment::class)->summary(Reservation::findOrFail($id)));
        $financial = \Mockery::mock(RentalPayment::class)->makePartial();
        $financial->shouldReceive('summary')->andReturn([...$summary, 'remaining_centimes' => 15000, 'pending_centimes' => 15000]);
        $this->app->instance(RentalPayment::class, $financial);
        $payments = DB::table('rental_payments')->get()->toArray();
        $this->receive($id, $this->returnInput($id))->assertCreated()->assertJsonPath('data.financial.remaining_centimes', 15000);
        $this->assertEquals($payments, DB::table('rental_payments')->get()->toArray());
    }

    public function test_invalid_dates_and_unreceived_car_cannot_record_a_return(): void
    {
        [,,,,$id] = $this->outFile();
        $input = $this->returnInput($id);
        foreach ([['vehicle_received' => false], ['returned_local' => '2026-10-22T09:00'], ['returned_local' => '2026-10-23T10:00'], ['reason' => '']] as $invalid) {
            $this->receive($id, [...$input, ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('rental_returns', 0);
    }

    public function test_return_control_media_and_signatures_are_private_replayable_and_versioned(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $input = $this->control($id);
        $input['photos'][] = UploadedFile::fake()->createWithContent('tour.webm', file_get_contents(base_path('tests/Fixtures/inspection.webm')));
        $signature = 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/signature.png')));
        $input = [...$input, 'signature_state' => 'signed', 'signature_reason' => null, 'client_signature' => $signature, 'agency_signature' => $signature];
        $inspection = $this->inspectReturn($id, $input)->assertCreated()->assertJsonPath('data.inspection.snapshot.distance_km', 200)->assertJsonPath('data.inspection.snapshot.fuel_difference_eighths', -2)->assertJsonCount(4, 'data.inspection.files')->json('data.inspection');
        $this->inspectReturn($id, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('rental_return_inspections', 1);
        foreach ($inspection['files'] as $file) {
            $this->get("/api/v1/reservations/$id/return/files/{$file['id']}/content")->assertOk()->assertHeader('Content-Type', $file['mime']);
        }
        $retained = array_column(array_filter($inspection['files'], fn ($f) => in_array($f['kind'], ['photo', 'video'])), 'id');
        $this->inspectReturn($id, $this->control($id, ['photos' => [], 'captions' => [], 'retained_file_ids' => $retained]))->assertCreated()->assertJsonPath('data.inspection.previous_id', $inspection['id'])->assertJsonPath('data.inspection.signature_state', 'pending');
        $this->assertDatabaseHas('rental_return_inspections', ['id' => $inspection['id'], 'signature_state' => 'signed']);
        $this->assertCount(4, Storage::disk('rental_returns')->allFiles());
    }

    public function test_bad_control_never_undoes_physical_return_and_old_control_cannot_be_overwritten(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        foreach ([['kilometers' => 1], ['fuel_eighths' => 9], ['photos' => []], ['signature_state' => 'signed'], ['condition' => 'anomalie_bloquante', 'notes' => null]] as $bad) {
            $this->inspectReturn($id, $this->control($id, $bad))->assertUnprocessable();
        }
        $first = $this->control($id);
        $this->inspectReturn($id, $first)->assertCreated();
        $this->inspectReturn($id, [...$first, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('rental_returns', 1);
        $this->assertDatabaseCount('rental_return_inspections', 1);
    }

    public function test_employee_can_receive_and_control_but_cannot_approve_responsible_only_readiness(): void
    {
        [$company,$owner,,,$id] = $this->outFile();
        config(['rental_returns.readiness_approvers' => 'responsible']);
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $inspection = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $input = ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Contrôle et disponibilité TEST', 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/return/readiness", $input)->assertForbidden();
        $this->actingAs($owner->user);
        $this->postJson("/api/v1/reservations/$id/return/readiness", $input)->assertCreated()->assertJsonPath('data.vehicle_ready', true);
        $this->postJson("/api/v1/reservations/$id/return/readiness", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('rental_return_readiness', 1);
        $this->inspectReturn($id, $this->control($id))->assertCreated()->assertJsonPath('data.vehicle_ready', false);
    }

    public function test_employee_can_approve_readiness_when_staff_policy_is_enabled(): void
    {
        [$company,,,, $id] = $this->outFile();
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create(['is_primary_responsible' => false]));
        $this->actingAs($employee->user);
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $inspection = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $input = ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Disponibilité contrôlée par le personnel TEST', 'request_key' => (string) Str::uuid()];
        config(['rental_returns.readiness_approvers' => 'pending']);
        $this->postJson("/api/v1/reservations/$id/return/readiness", $input)->assertForbidden();
        config(['rental_returns.readiness_approvers' => 'staff']);
        $this->getJson("/api/v1/reservations/$id/return")->assertOk()->assertJsonPath('data.can_approve_readiness', true)->assertJsonPath('data.readiness_policy_pending', false);
        $this->postJson("/api/v1/reservations/$id/return/readiness", $input)->assertCreated()->assertJsonPath('data.vehicle_ready', true);
        $this->postJson("/api/v1/reservations/$id/return/readiness", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('rental_return_readiness', ['reservation_id' => $id, 'actor_membership_id' => $employee->id, 'reason' => $input['reason']]);
        $this->assertDatabaseCount('rental_return_readiness', 1);
    }

    public function test_cross_tenant_reads_writes_and_return_file_reads_are_rejected(): void
    {
        [,,,,$id] = $this->outFile();
        $input = $this->returnInput($id);
        $this->receive($id, $input)->assertCreated();
        $file = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.files.0.id');
        $this->outFile();
        $this->getJson("/api/v1/reservations/$id/return")->assertNotFound();
        $this->receive($id, $input)->assertNotFound();
        $this->getJson("/api/v1/reservations/$id/return/files/$file/content")->assertNotFound();
    }

    public function test_return_events_are_immutable(): void
    {
        [,,,,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('rental_returns')->update(['reason' => 'Overwritten']);
    }

    public function test_date_correction_preserves_money_and_originals_and_invalidates_old_contract(): void
    {
        [,,,, $id] = $this->approvedFixture();
        $payments = DB::table('rental_payments')->get()->toJson();
        $versions = DB::table('rental_contract_versions')->get()->toJson();
        $files = DB::table('rental_contract_files')->get()->toJson();
        $before = DB::table('reservations')->where('id', $id)->first();
        $input = ['starts_local' => '2026-10-22T00:01', 'ends_local' => '2026-10-25T00:01', 'expected_starts_local' => '2026-10-22T10:00', 'expected_ends_local' => '2026-10-25T10:00', 'reason' => 'Correction heure convenue', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertCreated();
        $this->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($payments, DB::table('rental_payments')->get()->toJson());
        $this->assertSame($versions, DB::table('rental_contract_versions')->get()->toJson());
        $this->assertSame($files, DB::table('rental_contract_files')->get()->toJson());
        $after = DB::table('reservations')->where('id', $id)->first();
        $this->assertSame($before->frozen_total_centimes, $after->frozen_total_centimes);
        $this->assertSame($before->preparation_minutes, $after->preparation_minutes);
        $this->getJson("/api/v1/reservations/$id/departure/contract")->assertOk()->assertJsonPath('data.stale', true)->assertJsonPath('data.signed_verified', false);
        $this->assertDatabaseCount('rental_date_corrections', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('reservation_id', $id)->where('action', 'reservation_dates_corrected')->count());
        $input['request_key'] = (string) Str::uuid();
        $this->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertConflict();
    }

    public function test_date_correction_rejects_employee_changed_duration_and_conflicts(): void
    {
        [$company, $owner, $customer, $vehicle, $id] = $this->fixture();
        $input = ['starts_local' => '2026-10-21T00:01', 'ends_local' => '2026-10-24T00:01', 'expected_starts_local' => '2026-10-22T10:00', 'expected_ends_local' => '2026-10-25T10:00', 'reason' => 'Correction heure convenue', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];
        $employee = Tenant::run($company->id, fn () => CompanyMembership::factory()->create());
        $this->actingAs($employee->user)->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertForbidden();
        $this->actingAs($owner->user)->postJson("/api/v1/reservations/$id/date-corrections", [...$input, 'ends_local' => '2026-10-25T00:01'])->assertUnprocessable();
        $other = $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => '2026-10-20T00:01', 'ends_local' => '2026-10-21T12:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        Tenant::run($company->id, function () use ($other) { $r = Reservation::findOrFail($other); DB::table('reservations')->where('company_id', Tenant::id())->where('id', $other)->update(['status' => 'temporaire', 'hold_expires_at' => now()->addHour(), 'preparation_minutes' => 120, 'blocked_until' => $r->ends_at->addMinutes(120)]); });
        $this->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->assertDatabaseCount('rental_date_corrections', 0);
    }

    public function test_date_correction_is_tenant_scoped_and_forbidden_after_pickup(): void
    {
        [$company, $owner,,, $id] = $this->outFile();
        $input = ['starts_local' => '2026-10-22T00:01', 'ends_local' => '2026-10-25T00:01', 'expected_starts_local' => '2026-10-22T10:00', 'expected_ends_local' => '2026-10-25T10:00', 'reason' => 'Correction heure convenue', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertUnprocessable();
        $other = Company::factory()->create();
        $actor = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($actor->user)->withSession(['company_id' => $other->id])->postJson("/api/v1/reservations/$id/date-corrections", $input)->assertNotFound();
        $this->assertDatabaseCount('rental_date_corrections', 0);
    }

    public function test_immediate_request_requires_return_readiness_but_future_request_remains_available(): void
    {
        [,, $customer, $vehicle, $id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $this->travelTo(CarbonImmutable::now()->addHours(2));
        $start = CarbonImmutable::now(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i');
        $end = CarbonImmutable::now(RequestPeriod::TIMEZONE)->addDay()->format('Y-m-d\TH:i');
        $input = ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => $start, 'ends_local' => $end, 'request_key' => (string) Str::uuid()];
        $reason = 'Départ immédiat impossible · contrôlez le retour précédent et validez la voiture prête avant de créer cette demande';
        $this->getJson('/api/v1/reservations/availability?'.http_build_query(['starts_local' => $start, 'ends_local' => $end, 'show' => 'unavailable']))->assertOk()->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.reason', $reason);
        $count = DB::table('reservations')->count();
        $this->postJson('/api/v1/reservations', $input)->assertConflict()->assertJsonPath('code', 'UNIT_UNAVAILABLE');
        $this->assertDatabaseCount('reservations', $count);
        $future = CarbonImmutable::now(RequestPeriod::TIMEZONE)->addDays(2)->format('Y-m-d\TH:i');
        $futureEnd = CarbonImmutable::now(RequestPeriod::TIMEZONE)->addDays(3)->format('Y-m-d\TH:i');
        $this->getJson('/api/v1/reservations/availability?'.http_build_query(['starts_local' => $future, 'ends_local' => $futureEnd]))->assertOk()->assertJsonPath('data.0.available', true);
        config(['rental_returns.readiness_approvers' => 'responsible']);
        $inspection = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $this->postJson('/api/v1/reservations', $input)->assertConflict();
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Contrôle effectué, voiture prête', 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/reservations/availability?'.http_build_query(['starts_local' => $start, 'ends_local' => $end]))->assertOk()->assertJsonPath('data.0.available', true);
        $this->postJson('/api/v1/reservations', $input)->assertCreated();
    }

    public function test_real_late_return_does_not_conflict_with_later_confirmed_booking_and_pickup_waits_for_readiness(): void
    {
        [$company,$owner,$customer,$vehicle,$id] = $this->outFile();
        $next = $this->postJson('/api/v1/reservations', ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'starts_local' => '2026-10-25T12:00', 'ends_local' => '2026-10-28T12:00', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $quote = $this->postJson("/api/v1/reservations/$next/quote", ['total_centimes' => 270000, 'reason' => 'Tarif de test suivant', 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated()->json('financial.quote_id');
        $this->postJson("/api/v1/reservations/$next/payments", ['amount_centimes' => 270000, 'method' => 'especes', 'received_on' => '2026-10-22', 'receipt_attested' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/reservations/$next/confirm", ['quote_id' => $quote, 'agreement_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertCreated();
        $before = DB::table('reservations')->where('id', $next)->first();
        $this->travelTo(CarbonImmutable::parse('2026-10-25T13:00:00Z'));
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $this->assertEquals($before, DB::table('reservations')->where('id', $next)->first());
        $blocker = $this->getJson("/api/v1/reservations/$next/pickup")->assertOk()->json('data.return_blocker');
        $this->assertSame($id, $blocker['reservation_id']);
        $this->assertSame($vehicle->id, $blocker['vehicle_id']);
        $this->assertSame($vehicle->plate, $blocker['plate']);
        $this->getJson('/api/v1/reservations/availability?starts_local=2026-10-29T12:00&ends_local=2026-10-30T12:00')->assertOk()->assertJsonPath('data.0.available', true)->assertJsonPath('data.0.readiness_warning', 'Retour précédent : disponibilité après contrôle à valider avant la remise des clés.');
        $this->assertSame((int) DB::table('rental_returns')->where('reservation_id', $id)->value('id'), $blocker['return_id']);
        $checks = $this->getJson("/api/v1/reservations/$next/pickup")->assertOk()->json('data.checks');
        $this->assertFalse(collect($checks)->firstWhere('key', 'vehicle')['ok']);
        config(['rental_returns.readiness_approvers' => 'responsible']);
        $inspection = $this->inspectReturn($id, $this->control($id))->assertCreated()->json('data.inspection.id');
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Prête après contrôle TEST', 'request_key' => (string) Str::uuid()])->assertCreated();
        $checks = $this->getJson("/api/v1/reservations/$next/pickup")->assertOk()->json('data.checks');
        $this->assertTrue(collect($checks)->firstWhere('key', 'vehicle')['ok']);
        $this->getJson("/api/v1/reservations/$next/pickup")->assertOk()->assertJsonPath('data.return_blocker', null);
        $this->assertDatabaseCount('rental_payments', 2);
    }

    public function test_failed_photo_storage_keeps_physical_return_and_rolls_back_inspection_reading_and_files(): void
    {
        [,,,$vehicle,$id] = $this->outFile();
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $input = $this->control($id);
        $before = DB::table('vehicles')->where('id', $vehicle->id)->first();
        Storage::shouldReceive('disk')->with('rental_returns')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('delete')->once()->andReturn(true);
        $this->inspectReturn($id, $input)->assertServerError();
        $this->assertDatabaseCount('rental_returns', 1);
        $this->assertDatabaseCount('rental_return_inspections', 0);
        $this->assertEquals($before, DB::table('vehicles')->where('id', $vehicle->id)->first());
        $this->assertSame(0, DB::table('reservation_events')->where('action', 'return_inspection_recorded')->count());
    }

    public function test_blocking_anomaly_and_open_garage_never_allow_readiness_approval(): void
    {
        [,,,$vehicle,$id] = $this->outFile();
        config(['rental_returns.readiness_approvers' => 'responsible']);
        $this->receive($id, $this->returnInput($id))->assertCreated();
        $inspection = $this->inspectReturn($id, $this->control($id, ['condition' => 'anomalie_bloquante', 'notes' => 'Pneu à réparer']))->assertCreated()->json('data.inspection.id');
        $this->postJson("/api/v1/reservations/$id/return/readiness", ['inspection_id' => $inspection, 'vehicle_ready' => true, 'reason' => 'Test non autorisé', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('rental_return_readiness', 0);
    }
}
