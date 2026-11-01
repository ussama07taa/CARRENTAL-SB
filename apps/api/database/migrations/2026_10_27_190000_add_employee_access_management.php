<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('company_memberships',function(Blueprint $t){$t->boolean('is_active')->default(true);});
  DB::statement('ALTER TABLE company_memberships ADD CONSTRAINT primary_responsible_stays_active CHECK (NOT is_primary_responsible OR is_active)');
  Schema::create('employee_access_events',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('actor_membership_id');$t->unsignedBigInteger('subject_membership_id');$t->string('action',20);$t->string('reason',500);$t->timestampTz('created_at')->useCurrent();
   foreach(['actor_membership_id','subject_membership_id'] as $col)$t->foreign(['company_id',$col])->references(['company_id','id'])->on('company_memberships')->restrictOnDelete();
  });
  DB::statement("ALTER TABLE employee_access_events ADD CONSTRAINT employee_access_action CHECK (action IN ('created','disabled','enabled'))");
  DB::unprepared('CREATE TRIGGER employee_access_events_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON employee_access_events FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation()');
 }
 public function down(): void {throw new RuntimeException('Conserver les accès et leur historique.');}
};
