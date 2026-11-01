<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Actions\Companies\CreateCompanyWithResponsible;
class InstallationTest extends TestCase {
 use LazilyRefreshDatabase;
 protected function setUp(): void { parent::setUp(); config(['installation.token_hash'=>hash('sha256','private-installation-code')]); }
 private function payload(): array {return ['installation_code'=>'private-installation-code','company_name'=>'Nouvelle Agence','responsible_name'=>'Gérant','email'=>'owner@example.test','password'=>'Strong-private-password','password_confirmation'=>'Strong-private-password','address'=>'Adresse de l’agence','phone'=>'0600000000','agency_email'=>'contact@example.test','ice'=>'123456789012345'];}
 public function test_first_installation_is_atomic_and_closes_after_success(): void {
  $this->getJson('/api/v1/installation')->assertOk()->assertJson(['available'=>true]);
  $this->postJson('/api/v1/installation',$this->payload())->assertCreated();
  $this->assertDatabaseCount('companies',1);$this->assertDatabaseCount('users',1);
  $this->assertDatabaseCount('agency_profiles',1);$this->assertDatabaseCount('vehicles',0);$this->assertDatabaseCount('customers',0);
  $this->assertDatabaseHas('company_setup_events',['source'=>'installation_web']);
  $this->assertTrue(Hash::check('Strong-private-password',User::sole()->password));
  $this->getJson('/api/v1/installation')->assertJson(['available'=>false]);
  $this->postJson('/api/v1/installation',$this->payload())->assertStatus(409);
  $this->getJson('/api/v1/agency-branding')->assertJson(['name'=>'Nouvelle Agence','custom'=>true,'has_logo'=>false])->assertJsonMissing(['ice'=>'123456789012345']);
  $this->postJson('/api/v1/auth/login',['email'=>'owner@example.test','password'=>'Strong-private-password'])->assertNoContent();
  $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_manage_expenses',true);
 }
 public function test_wrong_code_cannot_create_any_account(): void {
  $this->postJson('/api/v1/installation',[...$this->payload(),'installation_code'=>'wrong'])->assertUnprocessable()->assertJsonValidationErrors('installation_code');
  $this->assertDatabaseCount('companies',0);$this->assertDatabaseCount('users',0);
 }
 public function test_disabled_setup_fails_closed(): void {
  config(['installation.token_hash'=>'']);
  $this->getJson('/api/v1/installation')->assertJson(['available'=>false]);
  $this->postJson('/api/v1/installation',$this->payload())->assertForbidden();
 }
 public function test_existing_agency_is_never_modified(): void {
  app(CreateCompanyWithResponsible::class)->handle('Existing','Owner','existing@example.test','Existing-secret-password');
  $hash=User::sole()->password;
  $this->postJson('/api/v1/installation',$this->payload())->assertStatus(409);
  $this->assertDatabaseHas('companies',['name'=>'Existing']);$this->assertSame($hash,User::sole()->password);
 }
 public function test_invalid_fields_do_not_leave_partial_setup(): void {
  $this->postJson('/api/v1/installation',[...$this->payload(),'ice'=>'bad','password_confirmation'=>'different'])->assertUnprocessable()->assertJsonValidationErrors(['ice','password']);
  $this->assertDatabaseCount('companies',0);$this->assertDatabaseCount('agency_profiles',0);
 }
 public function test_orphan_account_prevents_installation_takeover(): void {
  User::create(['name'=>'Existing','email'=>'existing@example.test','password'=>'Strong-password']);
  $this->postJson('/api/v1/installation',$this->payload())->assertStatus(409);
  $this->assertDatabaseCount('companies',0);$this->assertDatabaseCount('users',1);
 }

 public function test_logo_is_preserved_and_invalid_upload_is_rejected(): void {
  $this->post('/api/v1/installation',[...$this->payload(),'logo'=>\Illuminate\Http\UploadedFile::fake()->create('bad.svg',1,'image/svg+xml')],['Accept'=>'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
  $this->assertDatabaseCount('companies',0);
  $file=new \Illuminate\Http\UploadedFile(base_path('tests/Fixtures/signature.png'),'logo.png','image/png',null,true);
  $bytes=file_get_contents($file->getRealPath());
  $this->post('/api/v1/installation',[...$this->payload(),'logo'=>$file],['Accept'=>'application/json'])->assertCreated();
  $this->assertSame($bytes,$this->get('/api/v1/agency-branding/logo')->assertOk()->getContent());
  $id=DB::table('companies')->value('id');
  \App\Support\Tenancy\Tenant::run($id,function(): void {
   $profile=app(\App\Support\Reservations\AgencyDocumentProfile::class)->current();
   $this->assertSame('Nouvelle Agence',$profile['name']);
   $this->assertSame('123456789012345',$profile['ice']);
  });
 }
}
