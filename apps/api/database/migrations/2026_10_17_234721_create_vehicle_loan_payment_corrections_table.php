<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_loan_payment_corrections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->uuid('payment_key');
            $t->unsignedBigInteger('actor_membership_id');
            $t->integer('version');
            $t->bigInteger('before_centimes');
            $t->bigInteger('amount_centimes');
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'payment_key', 'version'], 'vlpc_version_unique');
            $t->unique(['company_id', 'request_key'], 'vlpc_request_unique');
            $t->foreign(['company_id', 'payment_key'], 'vlpc_payment_fk')->references(['company_id', 'request_key'])->on('vehicle_loan_payments')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vlpc_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_loan_payment_corrections ADD CONSTRAINT vlpc_values CHECK (version > 0 AND before_centimes BETWEEN 1 AND 1000000000 AND amount_centimes BETWEEN 1 AND 1000000000 AND before_centimes <> amount_centimes AND length(trim(reason)) > 0)');
        DB::unprepared('CREATE TRIGGER vehicle_loan_payment_corrections_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON vehicle_loan_payment_corrections FOR EACH STATEMENT EXECUTE FUNCTION reject_vehicle_loan_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_loan_payment_corrections');
    }
};
