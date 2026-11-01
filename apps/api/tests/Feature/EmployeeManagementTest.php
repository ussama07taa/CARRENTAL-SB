<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function agency(): array
    {
        $c = Company::factory()->create();

        return Tenant::run($c->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create()]);
    }

    private function login($m): void
    {
        $this->actingAs($m->user)->withSession(['company_id' => $m->company_id]);
    }

    private function input(): array
    {
        return ['name' => 'Employé Démo', 'email' => 'employee@example.test', 'password' => 'Demo-only-pass-2026', 'password_confirmation' => 'Demo-only-pass-2026'];
    }

    public function test_manager_creates_employee_once_without_promoting_or_exposing_password(): void
    {
        [$m,$staff] = $this->agency();
        $this->login($m);
        $id = $this->postJson('/api/v1/employees', $this->input())->assertCreated()->json('id');
        $u = User::where('email', 'employee@example.test')->sole();
        $this->assertTrue(Hash::check($this->input()['password'], $u->password));
        $this->assertDatabaseHas('company_memberships', ['id' => $id, 'company_id' => $m->company_id, 'is_primary_responsible' => false, 'is_active' => true]);
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(3, 'data')->assertDontSee('password')->assertDontSee($u->password);
        $this->postJson('/api/v1/employees', $this->input())->assertUnprocessable();
        $this->postJson('/api/v1/employees', [...$this->input(), 'email' => 'other@example.test', 'role' => 'unknown'])->assertUnprocessable();
        $this->assertDatabaseCount('employee_access_events', 1);
        $this->login($staff);
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->postJson('/api/v1/employees', [])->assertForbidden();
        $this->putJson('/api/v1/employees/'.$id, ['active' => false, 'reason' => 'test'])->assertForbidden();
    }

    public function test_disable_blocks_existing_session_and_reenable_preserves_history(): void
    {
        [$m,$staff] = $this->agency();
        $this->login($m);
        $this->putJson('/api/v1/employees/'.$m->id, ['active' => false, 'reason' => 'test'])->assertUnprocessable();
        $this->putJson('/api/v1/employees/'.$staff->id, ['active' => false, 'reason' => 'Fin du test'])->assertNoContent();
        $this->putJson('/api/v1/employees/'.$staff->id, ['active' => false, 'reason' => 'Fin du test'])->assertNoContent();
        $this->assertDatabaseCount('employee_access_events', 1);
        $this->login($staff);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->getJson('/api/v1/reservations')->assertForbidden();
        $this->login($m);
        $this->putJson('/api/v1/employees/'.$staff->id, ['active' => true, 'reason' => 'Reprise du test'])->assertNoContent();
        $this->login($staff);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_manage_expenses', false);
        $this->assertDatabaseCount('employee_access_events', 2);
    }

    public function test_cross_tenant_members_and_existing_email_cannot_be_taken_over(): void
    {
        [$m] = $this->agency();
        [$other,$staff] = $this->agency();
        $this->login($m);
        $this->getJson('/api/v1/employees')->assertOk()->assertDontSee($staff->user->email);
        $this->putJson('/api/v1/employees/'.$staff->id, ['active' => false, 'reason' => 'test'])->assertNotFound();
        $this->postJson('/api/v1/employees', [...$this->input(), 'email' => strtoupper($staff->user->email)])->assertUnprocessable();
        $this->assertDatabaseCount('employee_access_events', 0);
    }

    public function test_multiple_managers_share_access_and_last_active_manager_is_protected(): void
    {
        [$first,$staff]=$this->agency();$this->login($first);
        $id=$this->postJson('/api/v1/employees',[...$this->input(),'role'=>'responsable'])->assertCreated()->json('id');
        $second=Tenant::run($first->company_id,fn()=>CompanyMembership::findOrFail($id));
        $this->login($second);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_manage_expenses',true);
        $third=$this->postJson('/api/v1/employees',[...$this->input(),'email'=>'third@example.test','role'=>'responsable'])->assertCreated()->json('id');
        $this->putJson('/api/v1/employees/'.$first->id,['active'=>false,'reason'=>'Passation de gestion'])->assertNoContent();
        $this->putJson('/api/v1/employees/'.$third,['active'=>false,'reason'=>'Fin de mission'])->assertNoContent();
        $this->putJson('/api/v1/employees/'.$id,['active'=>false,'reason'=>'Fermeture'])->assertUnprocessable();
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('data.1.can_toggle',false);
        $this->login($first);
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->login($staff);
        $this->postJson('/api/v1/employees',[...$this->input(),'email'=>'forbidden@example.test','role'=>'responsable'])->assertForbidden();
        $this->assertDatabaseHas('employee_access_events',['subject_membership_id'=>$id,'action'=>'created','reason'=>'Compte gérant créé par un gérant.']);
        $this->login($second);
        $this->putJson('/api/v1/employees/'.$first->id,['active'=>true,'reason'=>'Reprise de gestion'])->assertNoContent();
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('data.1.can_toggle',true);
    }

    public function test_manager_edits_role_and_profile_resets_password_with_audit_and_guards():void
    {
        [$manager,$staff]=$this->agency();$this->login($manager);
        $payload=['name'=>'Updated employee','email'=>'updated@example.test','role'=>'responsable','reason'=>'Promotion validée'];
        $this->putJson('/api/v1/employees/'.$staff->id.'/profile',$payload)->assertNoContent();
        $this->assertDatabaseHas('company_memberships',['id'=>$staff->id,'is_primary_responsible'=>true]);
        $this->assertDatabaseHas('users',['id'=>$staff->user_id,'email'=>'updated@example.test']);
        $this->assertDatabaseHas('employee_access_events',['subject_membership_id'=>$staff->id,'action'=>'role_changed']);
        $this->assertDatabaseHas('employee_access_events',['subject_membership_id'=>$staff->id,'action'=>'profile_updated']);
        $this->putJson('/api/v1/employees/'.$staff->id.'/profile',[...$payload,'role'=>'employe'])->assertNoContent();
        $this->putJson('/api/v1/employees/'.$manager->id.'/profile',[...$payload,'email'=>$manager->user->email,'role'=>'employe'])->assertUnprocessable();
        $this->putJson('/api/v1/employees/'.$staff->id.'/profile',[...$payload,'email'=>$manager->user->email])->assertUnprocessable();
        $password='Changed-secret-2026!';
        $this->postJson('/api/v1/employees/'.$staff->id.'/password',['password'=>$password,'password_confirmation'=>'mismatch','reason'=>'Mot de passe oublié'])->assertUnprocessable();
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id'=>'session-test-user','user_id'=>$staff->user_id,'payload'=>'test','last_activity'=>time()]);
        $this->postJson('/api/v1/employees/'.$staff->id.'/password',['password'=>$password,'password_confirmation'=>$password,'reason'=>'Mot de passe oublié'])->assertNoContent();
        $this->assertTrue(Hash::check($password,User::findOrFail($staff->user_id)->password));
        $this->assertDatabaseMissing('sessions',['id'=>'session-test-user']);
        $this->getJson('/api/v1/employees')->assertOk()->assertDontSee($password);
        $this->assertDatabaseHas('employee_access_events',['subject_membership_id'=>$staff->id,'action'=>'password_reset']);
        [$foreign]=$this->agency();$this->login($manager);
        $this->putJson('/api/v1/employees/'.$foreign->id.'/profile',$payload)->assertNotFound();
        $this->postJson('/api/v1/employees/'.$foreign->id.'/password',['password'=>$password,'password_confirmation'=>$password,'reason'=>'Test accès'])->assertNotFound();
        $this->login($staff);
        $this->putJson('/api/v1/employees/'.$manager->id.'/profile',$payload)->assertForbidden();
        $this->postJson('/api/v1/employees/'.$manager->id.'/password',[])->assertForbidden();
    }

    public function test_disabled_employee_cannot_login(): void
    {
        [$m,$staff] = $this->agency();
        $this->login($m);
        $this->putJson('/api/v1/employees/'.$staff->id, ['active' => false, 'reason' => 'Test accès'])->assertNoContent();
        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->postJson('/api/v1/auth/login', ['email' => $staff->user->email, 'password' => 'password'])->assertForbidden();
    }
}
