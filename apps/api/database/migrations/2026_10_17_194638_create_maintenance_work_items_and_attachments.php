<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->uuid('item_key');
            $t->integer('version');
            $t->string('kind', 30);
            $t->string('title', 150);
            $t->string('status', 20);
            $t->string('cost_status', 20);
            $t->bigInteger('amount_centimes')->nullable();
            $t->date('completed_on')->nullable();
            $t->integer('kilometers')->nullable();
            $t->date('next_on')->nullable();
            $t->integer('next_km')->nullable();
            $t->string('reason', 500)->nullable();
            $t->text('notes')->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->unique(['company_id', 'maintenance_id', 'item_key', 'version'], 'maintenance_item_version');
            $t->unique(['company_id', 'request_key']);
            $t->unique(['company_id', 'id']);
        });
        Schema::create('maintenance_attachments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('path');
            $t->string('mime', 50);
            $t->string('sha256', 64);
            $t->uuid('request_key');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_work_items ADD CONSTRAINT maintenance_item_cost CHECK ((cost_status = 'unknown' AND amount_centimes IS NULL) OR (cost_status IN ('estimate','confirmed') AND amount_centimes BETWEEN 0 AND 1000000000)), ADD CONSTRAINT maintenance_item_status CHECK (status IN ('pending','progress','done','deferred')), ADD CONSTRAINT maintenance_item_completion CHECK (status <> 'done' OR completed_on IS NOT NULL)");
        foreach (['maintenance_work_items', 'maintenance_attachments'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation();");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_attachments');
        Schema::dropIfExists('maintenance_work_items');
    }
};
