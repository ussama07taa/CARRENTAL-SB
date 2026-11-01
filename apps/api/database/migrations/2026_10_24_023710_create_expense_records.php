<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('expense_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->integer('version');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('title', 150);
            $t->string('category', 30);
            $t->string('supplier', 150);
            $t->string('invoice_reference', 100)->nullable();
            $t->date('incurred_on');
            $t->date('due_on')->nullable();
            $t->bigInteger('amount_centimes');
            $t->boolean('cancelled')->default(false);
            $t->text('notes')->nullable();
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'expense_id', 'version']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'expense_id'])->references(['company_id', 'id'])->on('expenses')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('expense_allocations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('version_id');
            $t->unsignedBigInteger('vehicle_id')->nullable();
            $t->bigInteger('amount_centimes');
            $t->foreign(['company_id', 'version_id'])->references(['company_id', 'id'])->on('expense_versions')->restrictOnDelete();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->unique(['company_id', 'version_id', 'vehicle_id']);
        });
        DB::statement('CREATE UNIQUE INDEX expense_one_agency_share ON expense_allocations(company_id,version_id) WHERE vehicle_id IS NULL');
        Schema::create('expense_movements', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->uuid('movement_key');
            $t->integer('version');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('kind', 12);
            $t->bigInteger('amount_centimes');
            $t->string('method', 16);
            $t->date('occurred_on');
            $t->string('reference', 150)->nullable();
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'expense_id', 'movement_key', 'version']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'expense_id'])->references(['company_id', 'id'])->on('expenses')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('expense_documents', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('path');
            $t->string('mime', 100);
            $t->string('checksum', 64);
            $t->uuid('request_key');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'expense_id'])->references(['company_id', 'id'])->on('expenses')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE expense_versions ADD CONSTRAINT expense_amount_valid CHECK (amount_centimes BETWEEN 1 AND 1000000000 AND version > 0)');
        DB::statement('ALTER TABLE expense_allocations ADD CONSTRAINT expense_share_valid CHECK (amount_centimes BETWEEN 1 AND 1000000000)');
        DB::statement("ALTER TABLE expense_movements ADD CONSTRAINT expense_movement_valid CHECK (amount_centimes BETWEEN 0 AND 1000000000 AND (version > 1 OR amount_centimes > 0) AND version > 0 AND kind IN ('payment','refund') AND method IN ('cash','transfer','cheque','card'))");
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_expense_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Expense history is immutable'; END; $$");
        foreach (['expenses', 'expense_versions', 'expense_allocations', 'expense_movements', 'expense_documents'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_expense_mutation()");
        }
        DB::unprepared("CREATE OR REPLACE FUNCTION check_expense_allocation_total() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE target_company bigint; target_version bigint; expected bigint; actual bigint; BEGIN IF TG_TABLE_NAME = 'expense_versions' THEN target_company := NEW.company_id; target_version := NEW.id; ELSE target_company := NEW.company_id; target_version := NEW.version_id; END IF; SELECT amount_centimes INTO expected FROM expense_versions WHERE company_id=target_company AND id=target_version; SELECT COALESCE(SUM(amount_centimes),0) INTO actual FROM expense_allocations WHERE company_id=target_company AND version_id=target_version; IF expected <> actual THEN RAISE EXCEPTION 'Allocation total differs from expense' USING ERRCODE='23514'; END IF; RETURN NULL; END; $$");
        foreach (['expense_versions', 'expense_allocations'] as $table) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$table}_total AFTER INSERT ON $table DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_expense_allocation_total()");
        }
    }

    public function down(): void
    {
        foreach (['expense_documents', 'expense_movements', 'expense_allocations', 'expense_versions', 'expenses'] as $t) {
            Schema::dropIfExists($t);
        }DB::statement('DROP FUNCTION IF EXISTS check_expense_allocation_total()');
        DB::statement('DROP FUNCTION IF EXISTS reject_expense_mutation()');
    }
};
