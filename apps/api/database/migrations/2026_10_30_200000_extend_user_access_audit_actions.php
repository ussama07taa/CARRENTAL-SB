<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  DB::statement('ALTER TABLE employee_access_events DROP CONSTRAINT employee_access_action');
  DB::statement("ALTER TABLE employee_access_events ADD CONSTRAINT employee_access_action CHECK (action IN ('created','disabled','enabled','profile_updated','role_changed','password_reset'))");
 }
 public function down():void {throw new RuntimeException('Conserver l’historique des changements d’accès.');}
};
