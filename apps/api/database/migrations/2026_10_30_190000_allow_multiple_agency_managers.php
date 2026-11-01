<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::statement('ALTER TABLE company_memberships DROP CONSTRAINT primary_responsible_stays_active');DB::statement('DROP INDEX company_primary_responsible_unique');DB::statement('CREATE INDEX company_responsible_lookup ON company_memberships (company_id) WHERE is_primary_responsible');}
 public function down():void {
  // Refuse rollback while multiple managers exist; never silently demote accounts.
  DB::statement('CREATE UNIQUE INDEX company_primary_responsible_unique ON company_memberships (company_id) WHERE is_primary_responsible');
  DB::statement('ALTER TABLE company_memberships ADD CONSTRAINT primary_responsible_stays_active CHECK (NOT is_primary_responsible OR is_active)');
  DB::statement('DROP INDEX company_responsible_lookup');
 }
};
