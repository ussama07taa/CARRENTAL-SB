<?php
namespace Tests\Feature;
use App\Models\{Company,CompanyMembership,Customer,Reservation,Vehicle};
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class CustomerRentalsTest extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_customer_history_is_paginated_isolated_and_available_to_employee(): void
 {
  $company=Company::factory()->create();
  [$member,$customer,$other,$vehicle]=Tenant::run($company->id,fn()=>[CompanyMembership::factory()->create(),Customer::factory()->create(),Customer::factory()->create(),Vehicle::factory()->create()]);
  Tenant::run($company->id,function()use($customer,$other,$vehicle,$member){
   foreach(range(0,11) as $i)Reservation::create(['customer_id'=>$customer->id,'vehicle_id'=>$vehicle->id,'actor_membership_id'=>$member->id,'status'=>$i===11?'annulee':'demande','starts_at'=>now()->addDays($i+1),'ends_at'=>now()->addDays($i+2),'request_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64)]);
   Reservation::create(['customer_id'=>$other->id,'vehicle_id'=>$vehicle->id,'actor_membership_id'=>$member->id,'status'=>'demande','starts_at'=>now()->addDays(30),'ends_at'=>now()->addDays(31),'request_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('b',64)]);
  });
  $foreign=Company::factory()->create();
  $foreignCustomer=Tenant::run($foreign->id,fn()=>Customer::factory()->create());
  $this->actingAs($member->user)->withSession(['company_id'=>$company->id]);
  $url='/api/v1/customers/'.$customer->id.'/reservations';
  $response=$this->getJson($url)->assertOk()->assertJsonPath('total',12)->assertJsonPath('last_page',2)->assertJsonCount(10,'data')->assertJsonPath('data.0.status','annulee')->assertJsonPath('data.0.plate',$vehicle->plate)->assertJsonMissingPath('data.0.request_hash')->assertJsonMissingPath('data.0.company_id');
  $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(2,'data');
  $this->getJson('/api/v1/customers/'.$other->id.'/reservations')->assertOk()->assertJsonPath('total',1);
  $this->getJson('/api/v1/customers/'.$foreignCustomer->id.'/reservations')->assertNotFound();
  $this->getJson($url.'?page=0')->assertUnprocessable();
  $empty=Tenant::run($company->id,fn()=>Customer::factory()->create());
  $this->getJson('/api/v1/customers/'.$empty->id.'/reservations')->assertOk()->assertJsonPath('total',0)->assertJsonCount(0,'data');
  $this->assertDatabaseCount('reservations',13);
 }
 public function test_guest_and_missing_tenant_are_rejected():void
 {
  $this->getJson('/api/v1/customers/1/reservations')->assertUnauthorized();
  $company=Company::factory()->create();
  $member=Tenant::run($company->id,fn()=>CompanyMembership::factory()->create());
  $this->actingAs($member->user)->getJson('/api/v1/customers/1/reservations')->assertForbidden();
 }
}
