<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_payment_corrections', function (Blueprint $t): void {
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
            $t->unique(['company_id', 'request_key'], 'mpc_request_unique');
            $t->unique(['company_id', 'payment_key', 'version'], 'mpc_version_unique');
            $t->foreign(['company_id', 'payment_key'], 'mpc_payment_fk')->references(['company_id', 'request_key'])->on('maintenance_payments')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'mpc_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE maintenance_payment_corrections ADD CONSTRAINT mpc_amounts CHECK (before_centimes BETWEEN 1 AND 1000000000 AND amount_centimes BETWEEN 1 AND 1000000000 AND before_centimes <> amount_centimes AND version > 0)');
        DB::unprepared('CREATE TRIGGER mpc_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON maintenance_payment_corrections FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation();');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_payment_corrections');
    }
};
