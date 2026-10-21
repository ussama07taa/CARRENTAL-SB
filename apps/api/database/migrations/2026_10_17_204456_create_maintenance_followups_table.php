<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('work_id');
            $t->uuid('item_key');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('action', 20);
            $t->date('occurred_on');
            $t->date('follow_up_on')->nullable();
            $t->unsignedBigInteger('visit_id')->nullable();
            $t->text('note');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'maintenance_id', 'item_key']);
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance');
            $t->foreign(['company_id', 'work_id'])->references(['company_id', 'id'])->on('maintenance_work_items');
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships');
            $t->foreign(['company_id', 'visit_id'])->references(['company_id', 'id'])->on('maintenance_followups');
        });
        DB::statement("ALTER TABLE maintenance_followups ADD CONSTRAINT followup_action_check CHECK (action IN ('plan','start','complete','defer','return'))");
        DB::statement("CREATE UNIQUE INDEX maintenance_followup_return_once ON maintenance_followups (company_id,visit_id) WHERE action = 'return'");
        DB::statement('CREATE TRIGGER maintenance_followups_immutable BEFORE UPDATE OR DELETE ON maintenance_followups FOR EACH ROW EXECUTE FUNCTION reject_maintenance_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_followups');
    }
};
