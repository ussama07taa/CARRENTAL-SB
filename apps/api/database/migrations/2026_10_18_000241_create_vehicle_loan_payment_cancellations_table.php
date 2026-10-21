<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_loan_payment_cancellations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->uuid('payment_key');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('before_centimes');
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'payment_key'], 'vlpx_payment_unique');
            $t->unique(['company_id', 'request_key'], 'vlpx_request_unique');
            $t->foreign(['company_id', 'payment_key'], 'vlpx_payment_fk')->references(['company_id', 'request_key'])->on('vehicle_loan_payments')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vlpx_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_loan_payment_cancellations ADD CONSTRAINT vlpx_values CHECK (before_centimes BETWEEN 1 AND 1000000000 AND length(trim(reason)) > 0)');
        DB::unprepared('CREATE TRIGGER vehicle_loan_payment_cancellations_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON vehicle_loan_payment_cancellations FOR EACH STATEMENT EXECUTE FUNCTION reject_vehicle_loan_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_loan_payment_cancellations');
    }
};
