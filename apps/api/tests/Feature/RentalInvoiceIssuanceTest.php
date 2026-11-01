<?php
namespace Tests\Feature;
use App\Models\{Company,CompanyMembership,Customer,Vehicle};
use App\Support\Tenancy\Tenant;
use App\Support\Reservations\ContractPdf;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
class RentalInvoiceIssuanceTest extends TestCase {
 use LazilyRefreshDatabase;
 private function ready(): array {
  $this->withoutMiddleware(ThrottleRequests::class);
  $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-31T10:00Z'));
  config(['rental_contracts.real_documents_enabled'=>true,'rental_contracts.demo_only'=>false]);
  Storage::fake('rental_invoices');
  $c=Company::factory()->create();
  [$a,$client,$car]=Tenant::run($c->id,fn()=>[CompanyMembership::factory()->responsible()->create(),Customer::factory()->create(),Vehicle::factory()->create()]);
  DB::table('agency_profiles')->insert(['company_id'=>$c->id,'details'=>json_encode(['name'=>'Agence','address'=>'Tanger','ice'=>'123456789012345','rc'=>'123','if'=>'456']),'created_at'=>now(),'updated_at'=>now()]);
  $this->actingAs($a->user)->withSession(['company_id'=>$c->id]);
  $id=$this->postJson('/api/v1/reservations',['customer_id'=>$client->id,'vehicle_id'=>$car->id,'starts_local'=>'2027-01-22T10:00','ends_local'=>'2027-01-24T10:00','request_key'=>(string)Str::uuid()])->assertCreated()->json('data.id');
  $quote=$this->postJson("/api/v1/reservations/$id/quote",['total_centimes'=>120000,'vat_mode'=>'added','vat_rate'=>'20','reason'=>'Accord','agreement_confirmed'=>true,'request_key'=>(string)Str::uuid()])->assertCreated()->json('financial.quote_id');
  $this->postJson("/api/v1/reservations/$id/payments",['amount_centimes'=>144000,'method'=>'especes','received_on'=>'2026-10-31','receipt_attested'=>true,'request_key'=>(string)Str::uuid()])->assertCreated();
  $this->postJson("/api/v1/reservations/$id/confirm",['quote_id'=>$quote,'agreement_confirmed'=>true,'request_key'=>(string)Str::uuid()])->assertCreated();
  $input=['request_key'=>(string)Str::uuid(),'reviewed'=>true,'review_hash'=>$this->getJson("/api/v1/reservations/$id/invoice-preview")->assertOk()->json('review_hash')];
  return [$c,$a,$client,$id,$input];
 }
 public function test_original_number_replay_and_immutable_download():void {
  [$c,,$client,$id,$input]=$this->ready();
  $this->mock(ContractPdf::class,fn($m)=>$m->shouldReceive('renderHtml')->once()->andReturn('%PDF-1.4 original'));
  $url="/api/v1/reservations/$id/invoice";
  $this->postJson($url,$input)->assertCreated()->assertJsonPath('invoice.number','FAC-2026-0001');
  $this->postJson($url,$input)->assertOk()->assertJsonPath('replayed',true);
  $this->postJson($url,[...$input,'request_key'=>(string)Str::uuid()])->assertOk();
  DB::table('customers')->where('id',$client->id)->update(['first_name'=>'Changed']);
  DB::table('agency_profiles')->where('company_id',$c->id)->update(['details'=>json_encode(['name'=>'Changed'])]);
  $this->assertSame('%PDF-1.4 original',$this->get($url.'/content')->assertOk()->getContent());
  $this->assertDatabaseCount('rental_invoices',1);
  $this->assertEquals(1,DB::table('company_counters')->value('last_number'));
  $file=DB::table('rental_invoices')->first();Storage::disk('rental_invoices')->put($file->path,'bad');
  $this->get($url.'/content')->assertConflict();
 }
 public function test_stale_review_and_access_are_rejected():void {
  [$c,,$client,$id,$input]=$this->ready();
  DB::table('customers')->where('id',$client->id)->update(['first_name'=>'Changed']);
  $this->postJson("/api/v1/reservations/$id/invoice",$input)->assertConflict();
  $employee=Tenant::run($c->id,fn()=>CompanyMembership::factory()->create(['is_primary_responsible'=>false]));
  $this->actingAs($employee->user)->postJson("/api/v1/reservations/$id/invoice",$input)->assertForbidden();
  $other=Company::factory()->create();$manager=Tenant::run($other->id,fn()=>CompanyMembership::factory()->responsible()->create());
  $this->actingAs($manager->user)->withSession(['company_id'=>$other->id])->postJson("/api/v1/reservations/$id/invoice",$input)->assertNotFound();
  $this->assertDatabaseCount('rental_invoices',0);
 }
 public function test_render_failure_does_not_consume_number():void {
  [,,,$id,$input]=$this->ready();
  $this->mock(ContractPdf::class,fn($m)=>$m->shouldReceive('renderHtml')->once()->andThrow(new \RuntimeException('Renderer failed')));
  $this->postJson("/api/v1/reservations/$id/invoice",$input)->assertStatus(500);
  $this->assertDatabaseCount('company_counters',0);$this->assertDatabaseCount('rental_invoices',0);
  $this->mock(ContractPdf::class,fn($m)=>$m->shouldReceive('renderHtml')->once()->andReturn('%PDF-1.4 retry'));
  $this->postJson("/api/v1/reservations/$id/invoice",$input)->assertCreated()->assertJsonPath('invoice.number','FAC-2026-0001');
 }
 public function test_issued_pdf_has_official_number_in_both_languages():void {
  if(!config('rental_contracts.chrome'))$this->markTestSkipped('Chrome required');
  foreach(['fr','ar'] as $lang){
   [,, $client,$id,]=$this->ready();
   DB::table('customers')->where('id',$client->id)->update(['language'=>$lang]);
   $preview=$this->getJson("/api/v1/reservations/$id/invoice-preview")->assertOk();
   $this->postJson("/api/v1/reservations/$id/invoice",['request_key'=>(string)Str::uuid(),'reviewed'=>true,'review_hash'=>$preview->json('review_hash')])->assertCreated()->assertJsonPath('invoice.number','FAC-2026-0001');
   $bytes=$this->get("/api/v1/reservations/$id/invoice/content")->assertOk()->getContent();
   $this->assertStringStartsWith('%PDF-',$bytes);
  }
 }
}
