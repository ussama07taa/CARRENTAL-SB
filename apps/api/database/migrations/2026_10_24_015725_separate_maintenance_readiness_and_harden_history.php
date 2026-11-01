<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE maintenance_work_items DROP CONSTRAINT maintenance_item_cost, ADD CONSTRAINT maintenance_item_cost CHECK ((cost_status = 'unknown' AND amount_centimes IS NULL) OR (cost_status IN ('estimate','confirmed') AND amount_centimes IS NOT NULL AND amount_centimes BETWEEN 0 AND 1000000000))");
        DB::statement('DROP TRIGGER maintenance_followups_immutable ON maintenance_followups');
        DB::statement('CREATE TRIGGER maintenance_followups_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON maintenance_followups FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation()');
        Schema::table('maintenance_returns', function (Blueprint $table): void {
            $table->boolean('ready')->default(true);
        });
        Schema::table('maintenance_followups', function (Blueprint $table): void {
            $table->boolean('ready')->nullable();
            $table->unique(['company_id', 'maintenance_id', 'id'], 'maintenance_followup_parent_key');
        });
        Schema::create('maintenance_readiness', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('maintenance_id');
            $table->unsignedBigInteger('followup_return_id')->nullable();
            $table->unsignedBigInteger('actor_membership_id');
            $table->date('checked_on');
            $table->string('note', 500);
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'request_key']);
            $table->unique(['company_id', 'followup_return_id']);
            $table->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $table->foreign(['company_id', 'maintenance_id', 'followup_return_id'], 'maintenance_readiness_followup_fk')->references(['company_id', 'maintenance_id', 'id'])->on('maintenance_followups')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX maintenance_initial_readiness_once ON maintenance_readiness (company_id, maintenance_id) WHERE followup_return_id IS NULL');
        DB::statement('CREATE TRIGGER maintenance_readiness_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON maintenance_readiness FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_readiness');
        Schema::table('maintenance_followups', function (Blueprint $table): void {
            $table->dropUnique('maintenance_followup_parent_key');
            $table->dropColumn('ready');
        });
        Schema::table('maintenance_returns', function (Blueprint $table): void {
            $table->dropColumn('ready');
        });
        DB::statement('DROP TRIGGER maintenance_followups_immutable ON maintenance_followups');
        DB::statement('CREATE TRIGGER maintenance_followups_immutable BEFORE UPDATE OR DELETE ON maintenance_followups FOR EACH ROW EXECUTE FUNCTION reject_maintenance_mutation()');
        DB::statement("ALTER TABLE maintenance_work_items DROP CONSTRAINT maintenance_item_cost, ADD CONSTRAINT maintenance_item_cost CHECK ((cost_status = 'unknown' AND amount_centimes IS NULL) OR (cost_status IN ('estimate','confirmed') AND amount_centimes BETWEEN 0 AND 1000000000))");
    }
};
