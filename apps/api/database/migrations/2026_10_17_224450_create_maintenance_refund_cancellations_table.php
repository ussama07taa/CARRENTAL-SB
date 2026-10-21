<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_refund_cancellations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('refund_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('before_centimes');
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'request_key'], 'mrx_request_unique');
            $t->unique(['company_id', 'refund_id'], 'mrx_refund_unique');
            $t->foreign(['company_id', 'refund_id'], 'mrx_refund_fk')->references(['company_id', 'id'])->on('maintenance_refunds')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'mrx_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE maintenance_refund_cancellations ADD CONSTRAINT mrx_amount CHECK (before_centimes BETWEEN 1 AND 1000000000)');
        DB::unprepared('CREATE TRIGGER mrx_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON maintenance_refund_cancellations FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation();');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_refund_cancellations');
    }
};
