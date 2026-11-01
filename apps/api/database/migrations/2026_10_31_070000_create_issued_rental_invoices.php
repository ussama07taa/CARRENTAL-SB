<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  Schema::create('company_counters',function(Blueprint $t){
   $t->unsignedBigInteger('company_id');$t->string('kind',30);$t->unsignedInteger('year');$t->bigInteger('last_number');
   $t->primary(['company_id','kind','year']);$t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
  });
  Schema::create('rental_invoices',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('reservation_id');$t->unsignedBigInteger('actor_membership_id');
   $t->string('number',40);$t->jsonb('snapshot');$t->string('fingerprint',64);
   $t->string('path');$t->string('sha256',64);$t->unsignedBigInteger('size');
   $t->uuid('request_key');$t->string('request_hash',64);$t->timestampTz('created_at');
   $t->unique(['company_id','number']);$t->unique(['company_id','reservation_id']);$t->unique(['company_id','request_key']);$t->unique(['company_id','id']);
   $t->foreign(['company_id','reservation_id'])->references(['company_id','id'])->on('reservations')->restrictOnDelete();
   $t->foreign(['company_id','actor_membership_id'])->references(['company_id','id'])->on('company_memberships')->restrictOnDelete();
  });
  DB::statement('ALTER TABLE company_counters ADD CHECK(last_number>0)');
  DB::unprepared('CREATE TRIGGER rental_invoices_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_invoices FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation()');
 }
 public function down(): void {
  if(DB::table('rental_invoices')->exists())throw new RuntimeException('Conserver les factures et leurs numéros.');
  Schema::dropIfExists('rental_invoices');Schema::dropIfExists('company_counters');
 }
};
