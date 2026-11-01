<?php
namespace Tests\Feature;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Support\Tenancy\Tenant;
use App\Support\Customers\DocumentOcr;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerOcrTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function member(bool $allowed=true): CompanyMembership {
  $company=Company::factory()->create();
  return Tenant::run($company->id,fn()=>CompanyMembership::factory()->create(['is_primary_responsible'=>$allowed]));
 }
 private function login($member): void {$this->actingAs($member->user)->withSession(['company_id'=>$member->company_id]);}
 private function image(): UploadedFile {
  $chunk=fn($t,$d)=>pack('N',strlen($d)).$t.$d.pack('N',crc32($t.$d));
  return UploadedFile::fake()->createWithContent('test.png',"\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',100,100,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x60\x80\x60",100),100))).$chunk('IEND',''));
 }
 public function test_authorized_read_returns_proposals_without_writes(): void {
  $this->login($this->member());
  $this->mock(DocumentOcr::class,fn($mock)=>$mock->shouldReceive('read')->once()->andReturn(['first_name'=>'TEST','licence_number'=>'12/345678']));
  $this->postJson('/api/v1/customers/ocr',['kind'=>'permis','image'=>$this->image()])->assertOk()->assertJsonPath('saved',false)->assertJsonPath('identity_verified',false)->assertJsonPath('fields.first_name','TEST')->assertHeader('Cache-Control','max-age=0, no-store, private');
  $this->assertDatabaseCount('customers',0);$this->assertDatabaseCount('customer_documents',0);
 }
 public function test_employee_and_missing_tenant_are_denied(): void {
  $m=$this->member(false);$this->login($m);
  $this->getJson('/api/v1/customers/ocr')->assertOk()->assertJsonPath('allowed',false);
  $this->postJson('/api/v1/customers/ocr')->assertForbidden();
  $this->withSession(['company_id'=>null])->postJson('/api/v1/customers/ocr')->assertForbidden();
 }
 public function test_cross_tenant_customer_and_invalid_files_are_rejected(): void {
  $m=$this->member();$other=$this->member();
  $customer=Tenant::run($other->company_id,fn()=>Customer::factory()->create());$this->login($m);
  $this->postJson('/api/v1/customers/ocr',['kind'=>'cin','image'=>$this->image(),'customer_id'=>$customer->id])->assertNotFound();
  $this->postJson('/api/v1/customers/ocr',['kind'=>'passport','image'=>UploadedFile::fake()->createWithContent('bad.jpg','not-image')])->assertUnprocessable();
 }
 public function test_disabled_engine_has_safe_error(): void {
  config(['ocr.enabled'=>false]);$this->login($this->member());
  $this->postJson('/api/v1/customers/ocr',['kind'=>'cin','image'=>$this->image()])->assertStatus(503);
 }
}
