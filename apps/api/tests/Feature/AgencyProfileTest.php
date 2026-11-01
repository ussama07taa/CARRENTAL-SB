<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
class AgencyProfileTest extends TestCase {
 use LazilyRefreshDatabase;
 private function actor(bool $manager=true){
  $c=Company::factory()->create();
  $m=Tenant::run($c->id,fn()=> $manager ? CompanyMembership::factory()->responsible()->create() : CompanyMembership::factory()->create());
  $this->actingAs($m->user)->withSession(['company_id'=>$c->id]);return $m;
 }
 private function payload(): array {return ['expected_revision'=>$this->getJson('/api/v1/settings/agency')->assertOk()->json('revision'),'name'=>'Agence nouvelle','address'=>'Adresse nouvelle','phone'=>'0600000000','email'=>'contact@example.test','ice'=>'123456789012345','rc'=>'RC 123'];}
 public function test_manager_updates_own_profile_and_records_one_immutable_event(): void {
  $m=$this->actor();$p=$this->payload();
  $this->postJson('/api/v1/settings/agency',$p)->assertOk()->assertJsonPath('details.rc','RC 123')->assertJsonCount(1,'history');
  $this->assertDatabaseHas('companies',['id'=>$m->company_id,'name'=>'Agence nouvelle']);
  $this->assertDatabaseHas('company_setup_events',['company_id'=>$m->company_id,'responsible_membership_id'=>$m->id,'source'=>'agency_profile_updated']);
  $this->postJson('/api/v1/settings/agency',$this->payload())->assertOk()->assertJsonCount(1,'history');
  $this->postJson('/api/v1/settings/agency',[...$p,'rc'=>'stale overwrite'])->assertConflict();
  $this->getJson('/api/v1/settings/agency')->assertJsonPath('details.rc','RC 123');
 }
 public function test_employee_and_missing_tenant_cannot_read_or_write(): void {
  $m=$this->actor(false);
  $this->getJson('/api/v1/settings/agency')->assertForbidden();
  $this->postJson('/api/v1/settings/agency',[])->assertForbidden();
  $this->withSession(['company_id'=>999999])->getJson('/api/v1/settings/agency')->assertForbidden();
  $this->assertDatabaseCount('agency_profiles',0);
 }
 public function test_other_tenant_is_not_read_or_changed(): void {
  $other=Company::factory()->create(['name'=>'Autre agence']);
  DB::table('agency_profiles')->insert(['company_id'=>$other->id,'details'=>json_encode(['name'=>'SECRET OTHER','ice'=>'999999999999999']),'created_at'=>now(),'updated_at'=>now()]);
  $this->actor();$p=$this->payload();
  $this->getJson('/api/v1/settings/agency')->assertDontSee('SECRET OTHER');
  $this->postJson('/api/v1/settings/agency',[...$p,'company_id'=>$other->id])->assertUnprocessable();
  $this->postJson('/api/v1/settings/agency',$p)->assertOk();
  $this->assertSame('SECRET OTHER',json_decode(DB::table('agency_profiles')->where('company_id',$other->id)->value('details'),true)['name']);
 }
 public function test_validation_preserves_profile_and_logo_can_be_replaced_and_removed(): void {
  $this->actor();$p=$this->payload();
  $this->postJson('/api/v1/settings/agency',[...$p,'ice'=>'bad'])->assertUnprocessable()->assertJsonValidationErrors('ice');
  $this->assertDatabaseCount('agency_profiles',0);
  $logo=new UploadedFile(base_path('tests/Fixtures/signature.png'),'logo.png','image/png',null,true);
  $this->post('/api/v1/settings/agency',[...$p,'logo'=>$logo],['Accept'=>'application/json'])->assertOk();
  $this->assertStringStartsWith('data:image/png;base64,',$this->getJson('/api/v1/settings/agency')->json('logo'));
  $this->postJson('/api/v1/settings/agency',[...$this->payload(),'remove_logo'=>true])->assertOk()->assertJsonPath('logo',null);
  $this->assertDatabaseCount('company_setup_events',2);
 }
}
