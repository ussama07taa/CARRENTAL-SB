<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_fines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->timestampTz('occurred_at');
            $t->date('received_on');
            $t->string('authority', 150);
            $t->string('reference', 100);
            $t->string('reference_key', 260);
            $t->string('description', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->unique(['company_id', 'reference_key']);
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            foreach (['vehicle_id' => 'vehicles', 'actor_membership_id' => 'company_memberships'] as $c => $table) {
                $t->foreign(['company_id', $c])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('traffic_fine_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('fine_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('action', 30);
            $t->string('status', 20)->nullable();
            $t->string('note', 1000);
            $t->date('next_on')->nullable();
            $t->unsignedBigInteger('pickup_id')->nullable();
            $t->unsignedBigInteger('expense_id')->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'fine_id', 'id']);
            foreach (['fine_id' => 'traffic_fines', 'actor_membership_id' => 'company_memberships', 'pickup_id' => 'rental_pickups', 'expense_id' => 'expenses'] as $c => $table) {
                $t->foreign(['company_id', $c])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('traffic_fine_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('fine_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('path');
            $t->string('mime', 50);
            $t->unsignedInteger('size');
            $t->string('checksum', 64);
            $t->uuid('request_key');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            foreach (['fine_id' => 'traffic_fines', 'actor_membership_id' => 'company_memberships'] as $c => $table) {
                $t->foreign(['company_id', $c])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE traffic_fine_events ADD CONSTRAINT traffic_fine_action_check CHECK(action IN ('followup','rental_review','expense')), ADD CONSTRAINT traffic_fine_status_check CHECK(status IS NULL OR status IN ('open','disputed','closed'))");
        DB::statement("CREATE UNIQUE INDEX traffic_fine_one_expense ON traffic_fine_events(company_id,fine_id) WHERE action='expense'");
        DB::statement('CREATE UNIQUE INDEX traffic_fine_expense_source ON traffic_fine_events(company_id,expense_id) WHERE expense_id IS NOT NULL');
        foreach (['traffic_fines', 'traffic_fine_events', 'traffic_fine_documents'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation()");
        }
    }

    public function down(): void
    {
        foreach (['traffic_fines', 'traffic_fine_events', 'traffic_fine_documents'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Conserver les dossiers de contravention.');
            }
        }
        Schema::drop('traffic_fine_documents');
        Schema::drop('traffic_fine_events');
        Schema::drop('traffic_fines');
    }
};
