<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_tickets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('owner_membership_id');
            $t->unsignedInteger('version')->default(1);
            $t->string('title', 160);
            $t->string('zone', 100);
            $t->text('description');
            $t->timestampTz('occurred_at');
            $t->string('mobility', 20)->default('review');
            $t->string('repair', 20)->default('review');
            $t->date('follow_up_on');
            $t->string('next_action', 250);
            $t->boolean('closed')->default(false);
            $t->timestampsTz();
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'vehicle_id', 'closed']);
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            foreach (['vehicle_id' => 'vehicles', 'owner_membership_id' => 'company_memberships'] as $field => $table) {
                $t->foreign(['company_id', $field])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('damage_observations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('inspection_id');
            $t->string('kind', 20);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'ticket_id', 'inspection_id']);
            $t->foreign(['company_id', 'ticket_id'])->references(['company_id', 'id'])->on('damage_tickets')->restrictOnDelete();
            $t->foreign(['company_id', 'inspection_id'])->references(['company_id', 'id'])->on('rental_return_inspections')->restrictOnDelete();
        });
        Schema::create('damage_maintenance_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'ticket_id', 'maintenance_id']);
            $t->foreign(['company_id', 'ticket_id'])->references(['company_id', 'id'])->on('damage_tickets')->restrictOnDelete();
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
        });
        Schema::create('damage_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('kind', 30);
            $t->text('reason');
            $t->jsonb('payload');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'ticket_id', 'id']);
            $t->foreign(['company_id', 'ticket_id'])->references(['company_id', 'id'])->on('damage_tickets')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE damage_tickets ADD CHECK(mobility IN ('review','blocked','usable')), ADD CHECK(repair IN ('review','deferred','garage','done')), ADD CHECK(version>0)");
        DB::statement("ALTER TABLE damage_observations ADD CHECK(kind IN ('new','known','worsened'))");
        foreach (['damage_entries', 'damage_observations', 'damage_maintenance_links'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation()");
        }
    }

    public function down(): void
    {
        if (DB::table('damage_tickets')->exists()) {
            throw new RuntimeException('Conserver les dossiers de dommages et leur historique.');
        }
        foreach (['damage_entries', 'damage_maintenance_links', 'damage_observations', 'damage_tickets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
