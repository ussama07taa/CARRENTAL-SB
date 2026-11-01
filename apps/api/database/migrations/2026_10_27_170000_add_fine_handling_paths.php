<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_fine_events', function (Blueprint $t) {
            $t->jsonb('handling')->nullable();
            $t->unsignedBigInteger('evidence_document_id')->nullable();
            $t->foreign(['company_id', 'evidence_document_id'])->references(['company_id', 'id'])->on('traffic_fine_documents')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE traffic_fine_events DROP CONSTRAINT traffic_fine_action_check');
        DB::statement("ALTER TABLE traffic_fine_events ADD CONSTRAINT traffic_fine_action_check CHECK(action IN ('followup','rental_review','expense','declaration','client_direct'))");
    }

    public function down(): void
    {
        if (DB::table('traffic_fine_events')->whereIn('action', ['declaration', 'client_direct'])->exists()) {
            throw new RuntimeException('Conserver les démarches enregistrées.');
        }
        DB::statement('ALTER TABLE traffic_fine_events DROP CONSTRAINT traffic_fine_action_check');
        DB::statement("ALTER TABLE traffic_fine_events ADD CONSTRAINT traffic_fine_action_check CHECK(action IN ('followup','rental_review','expense'))");
        Schema::table('traffic_fine_events', function (Blueprint $t) {
            $t->dropForeign(['company_id', 'evidence_document_id']);
            $t->dropColumn(['handling', 'evidence_document_id']);
        });
    }
};
