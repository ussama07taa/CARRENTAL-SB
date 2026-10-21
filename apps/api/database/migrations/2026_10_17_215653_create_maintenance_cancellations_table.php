<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_cancellations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('work_id')->nullable();
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('target', 40);
            $t->bigInteger('before_centimes')->nullable();
            $t->string('reason', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'maintenance_id', 'target'], 'mc_target_unique');
            $t->unique(['company_id', 'request_key'], 'mc_request_unique');
            $t->foreign(['company_id', 'maintenance_id'], 'mc_entry_fk')->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->foreign(['company_id', 'work_id'], 'mc_work_fk')->references(['company_id', 'id'])->on('maintenance_work_items')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'mc_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_cancellations ADD CONSTRAINT mc_target CHECK ((target = 'file' AND work_id IS NULL) OR (target <> 'file' AND work_id IS NOT NULL)), ADD CONSTRAINT mc_amount CHECK (before_centimes IS NULL OR before_centimes BETWEEN 0 AND 1000000000)");
        DB::unprepared('CREATE TRIGGER mc_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON maintenance_cancellations FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation();');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_cancellations');
    }
};
