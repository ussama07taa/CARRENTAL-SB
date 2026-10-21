<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\DamageTickets;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DamageTicketsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $company = Company::factory()->create();
        [$owner,$employee,$vehicle] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create(), Vehicle::factory()->create()]);
        $this->actingAs($owner->user)->withSession(['company_id' => $company->id]);

        return [$company, $owner, $employee, $vehicle];
    }

    private function input($owner, $vehicle): array
    {
        return ['kind' => 'create', 'vehicle_id' => $vehicle->id, 'title' => 'Rayure portière', 'zone' => 'Côté droit', 'description' => 'Rayure visible à examiner', 'occurred_local' => now('Africa/Casablanca')->subMinute()->format('Y-m-d\TH:i'), 'owner_membership_id' => $owner->id, 'follow_up_on' => now()->toDateString(), 'next_action' => 'Examiner les photos et la sécurité', 'reason' => 'Signalement de test', 'request_key' => (string) Str::uuid()];
    }

    private function action(int $id, string $kind, array $values = [])
    {
        $version = DB::table('damage_tickets')->where('id', $id)->value('version');

        return $this->postJson('/api/v1/damages/'.$id, ['kind' => $kind, 'expected_version' => $version, 'request_key' => (string) Str::uuid(), 'reason' => 'Mise à jour justifiée pour le test', ...$values]);
    }

    public function test_create_replay_conflict_and_cross_tenant_access(): void
    {
        [$company,$owner,$employee,$vehicle] = $this->fixture();
        $body = $this->input($owner, $vehicle);
        $id = $this->postJson('/api/v1/damages', $body)->assertCreated()->json('id');
        $this->postJson('/api/v1/damages', $body)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/damages', [...$body, 'title' => 'Autre dommage'])->assertConflict();
        $this->assertSame(1, DB::table('damage_tickets')->count());
        $this->assertSame(1, DB::table('damage_entries')->count());
        $this->postJson('/api/v1/damages/'.$id, ['kind' => 'reopen', 'expected_version' => 999, 'reason' => 'Test conflit', 'request_key' => (string) Str::uuid()])->assertConflict();
        $other = Company::factory()->create();
        $otherActor = Tenant::run($other->id, fn () => CompanyMembership::factory()->responsible()->create());
        $this->actingAs($otherActor->user)->withSession(['company_id' => $other->id]);
        $this->getJson('/api/v1/damages/'.$id)->assertNotFound();
        $this->getJson('/api/v1/damages')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/damages', [...$body, 'request_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_employee_can_report_but_cannot_approve_readiness_or_finance(): void
    {
        [$company,$owner,$employee,$vehicle] = $this->fixture();
        $this->actingAs($employee->user);
        $id = $this->postJson('/api/v1/damages', $this->input($employee, $vehicle))->assertCreated()->json('id');
        $follow = ['owner_membership_id' => $employee->id, 'follow_up_on' => now()->toDateString(), 'next_action' => 'Examiner demain'];
        $this->action($id, 'followup', $follow)->assertCreated();
        $this->action($id, 'followup', [...$follow, 'mobility' => 'usable', 'checked' => true])->assertForbidden();
        $this->action($id, 'followup', [...$follow, 'repair' => 'done'])->assertForbidden();
        $this->action($id, 'insurance', ['insurer' => 'TEST', 'reference' => 'REF123', 'requested_centimes' => 800000, 'approved_centimes' => 600000, 'evidence_reference' => 'Accord documenté'])->assertForbidden();
        $this->getJson('/api/v1/damages/'.$id)->assertOk()->assertJsonPath('financial', null)->assertJsonPath('can_decide', false);
    }

    public function test_deferred_damage_requires_explicit_readiness_and_insurance_remains_independent(): void
    {
        [$company,$owner,$employee,$vehicle] = $this->fixture();
        $id = $this->postJson('/api/v1/damages', $this->input($owner, $vehicle))->assertCreated()->json('id');
        $this->assertContains($vehicle->id, Tenant::run($company->id, fn () => app(DamageTickets::class)->blockers()));
        $follow = ['owner_membership_id' => $owner->id, 'follow_up_on' => now()->toDateString(), 'next_action' => 'Relancer assurance', 'repair' => 'deferred'];
        $this->action($id, 'followup', $follow)->assertCreated();
        $this->assertContains($vehicle->id, Tenant::run($company->id, fn () => app(DamageTickets::class)->blockers()));
        $this->action($id, 'followup', [...$follow, 'mobility' => 'usable'])->assertUnprocessable();
        $this->action($id, 'followup', [...$follow, 'mobility' => 'usable', 'checked' => true])->assertCreated();
        $this->assertNotContains($vehicle->id, Tenant::run($company->id, fn () => app(DamageTickets::class)->blockers()));
        $this->action($id, 'insurance', ['insurer' => 'TEST', 'reference' => 'REF123', 'requested_centimes' => 800000, 'approved_centimes' => null, 'evidence_reference' => 'Demande transmise'])->assertCreated();
        $this->getJson('/api/v1/damages/'.$id)->assertJsonPath('financial.insurance.approved_centimes', null);
        $this->action($id, 'receipt', ['amount_centimes' => 200000, 'received_on' => now()->toDateString(), 'method' => 'transfer', 'reference' => 'VERSEMENT1', 'checked' => true])->assertUnprocessable();
        $this->action($id, 'insurance', ['insurer' => 'TEST', 'reference' => 'REF123', 'requested_centimes' => 800000, 'approved_centimes' => 600000, 'evidence_reference' => 'Accord reçu'])->assertCreated();
        $this->action($id, 'receipt', ['amount_centimes' => 200000, 'received_on' => now()->toDateString(), 'method' => 'transfer', 'reference' => 'VERSEMENT1', 'checked' => true])->assertCreated();
        $this->getJson('/api/v1/damages/'.$id)->assertOk()->assertJsonPath('financial.received_centimes', 200000)->assertJsonPath('ticket.mobility', 'usable')->assertJsonPath('financial.decision', null);
        $this->action($id, 'receipt', ['amount_centimes' => 400001, 'received_on' => now()->toDateString(), 'method' => 'transfer', 'reference' => 'VERSEMENT2', 'checked' => true])->assertUnprocessable();
        $this->action($id, 'close', ['checked' => true])->assertUnprocessable();
        $entry = DB::table('damage_entries')->where('ticket_id', $id)->where('kind', 'receipt')->value('id');
        $this->action($id, 'receipt_void', ['entry_id' => $entry])->assertCreated();
        $this->action($id, 'receipt_void', ['entry_id' => $entry])->assertUnprocessable();
        $this->getJson('/api/v1/damages/'.$id)->assertJsonPath('financial.received_centimes', 0)->assertJsonPath('financial.receipts.0.voided', true);
        $this->assertDatabaseHas('damage_entries', ['id' => $entry, 'kind' => 'receipt']);
    }

    public function test_future_fact_and_foreign_owner_are_rejected(): void
    {
        [$company,$owner,$employee,$vehicle] = $this->fixture();
        $input = $this->input($owner, $vehicle);
        $this->postJson('/api/v1/damages', [...$input, 'occurred_local' => now()->addDays(2)->format('Y-m-d\TH:i')])->assertUnprocessable();
        $other = Company::factory()->create();
        $alien = Tenant::run($other->id, fn () => CompanyMembership::factory()->create());
        $this->postJson('/api/v1/damages', [...$input, 'owner_membership_id' => $alien->id])->assertNotFound();
        $this->assertSame(0, DB::table('damage_tickets')->count());
    }

    public function test_repair_link_does_not_duplicate_cost_and_history_is_immutable(): void
    {
        [$company,$owner,$employee,$vehicle] = $this->fixture();
        $id = $this->postJson('/api/v1/damages', $this->input($owner, $vehicle))->assertCreated()->json('id');
        $maintenance = $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/maintenance', ['kind' => 'repair', 'title' => 'Réparation carrosserie', 'performed_on' => now()->toDateString(), 'cost_centimes' => 800000, 'paid_centimes' => 0, 'in_garage' => false, 'request_key' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->action($id, 'maintenance', ['maintenance_id' => $maintenance])->assertCreated();
        $this->action($id, 'maintenance', ['maintenance_id' => $maintenance])->assertConflict();
        $this->assertDatabaseCount('vehicle_maintenance', 1);
        $this->assertSame(800000, (int) DB::table('vehicle_maintenance')->sum('cost_centimes'));
        $this->assertDatabaseCount('damage_maintenance_links', 1);
        $this->getJson('/api/v1/damages/'.$id)->assertJsonPath('maintenance_ids.0', $maintenance);
        try {
            DB::transaction(fn () => DB::table('damage_entries')->where('ticket_id',$id)->update(['reason' => 'Modification interdite']));
            $this->fail('The history must be immutable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only',strtolower($e->getMessage()));
        }
    }
}
