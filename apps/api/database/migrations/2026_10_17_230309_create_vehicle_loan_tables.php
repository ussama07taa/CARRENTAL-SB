<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_loans', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('lender', 150);
            $t->string('reference', 100);
            $t->string('identity_key', 64);
            $t->date('signed_on')->nullable();
            $t->date('tracking_from');
            $t->bigInteger('financed_centimes')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'identity_key']);
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'vehicle_id']);
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vl_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('vehicle_loan_installments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('loan_id');
            $t->integer('position');
            $t->date('due_on');
            $t->bigInteger('amount_centimes');
            $t->bigInteger('opening_paid_centimes')->default(0);
            $t->unique(['company_id', 'loan_id', 'id'], 'vli_scope_unique');
            $t->unique(['company_id', 'loan_id', 'position'], 'vli_position_unique');
            $t->index(['company_id', 'due_on']);
            $t->foreign(['company_id', 'loan_id'], 'vli_loan_fk')->references(['company_id', 'id'])->on('vehicle_loans')->restrictOnDelete();
        });
        Schema::create('vehicle_loan_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('loan_id');
            $t->unsignedBigInteger('installment_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('amount_centimes');
            $t->date('paid_on');
            $t->string('method', 20);
            $t->string('reference', 150)->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'request_key'], 'vlp_request_unique');
            $t->foreign(['company_id', 'loan_id', 'installment_id'], 'vlp_installment_fk')->references(['company_id', 'loan_id', 'id'])->on('vehicle_loan_installments')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vlp_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('vehicle_loan_documents', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('loan_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('path');
            $t->string('mime', 50);
            $t->string('sha256', 64);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'request_key'], 'vld_request_unique');
            $t->foreign(['company_id', 'loan_id'], 'vld_loan_fk')->references(['company_id', 'id'])->on('vehicle_loans')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vld_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_loans ADD CONSTRAINT vl_financed CHECK (financed_centimes IS NULL OR financed_centimes BETWEEN 1 AND 1000000000)');
        DB::statement('ALTER TABLE vehicle_loan_installments ADD CONSTRAINT vli_amounts CHECK (position BETWEEN 1 AND 360 AND amount_centimes BETWEEN 1 AND 1000000000 AND opening_paid_centimes BETWEEN 0 AND amount_centimes)');
        DB::statement("ALTER TABLE vehicle_loan_payments ADD CONSTRAINT vlp_amount_method CHECK (amount_centimes BETWEEN 1 AND 1000000000 AND method IN ('cash','transfer','card'))");
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_vehicle_loan_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Loan records are append-only'; END; $$ LANGUAGE plpgsql;");
        foreach (['vehicle_loans', 'vehicle_loan_installments', 'vehicle_loan_payments', 'vehicle_loan_documents'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_vehicle_loan_mutation();");
        }
    }

    public function down(): void
    {
        foreach (['vehicle_loan_documents', 'vehicle_loan_payments', 'vehicle_loan_installments', 'vehicle_loans'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS reject_vehicle_loan_mutation()');
    }
};
