<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_loan_cancellations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('loan_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('before_remaining_centimes');
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'loan_id'], 'vlcx_payment_unique');
            $t->unique(['company_id', 'request_key'], 'vlcx_request_unique');
            $t->foreign(['company_id', 'loan_id'], 'vlcx_payment_fk')->references(['company_id', 'id'])->on('vehicle_loans')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vlcx_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_loan_cancellations ADD CONSTRAINT vlcx_values CHECK (before_remaining_centimes > 0 AND length(trim(reason)) > 0)');
        DB::unprepared('CREATE TRIGGER vehicle_loan_cancellations_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON vehicle_loan_cancellations FOR EACH STATEMENT EXECUTE FUNCTION reject_vehicle_loan_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_loan_cancellations');
    }
};
