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
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('actor_membership_id');
            $table->string('status', 20)->default('demande');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'request_key']);
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'vehicle_id', 'starts_at']);
            foreach (['customer_id' => 'customers', 'vehicle_id' => 'vehicles', 'actor_membership_id' => 'company_memberships'] as $column => $parent) {
                $table->foreign(['company_id', $column])->references(['company_id', 'id'])->on($parent)->restrictOnDelete();
            }
        });
        Schema::create('reservation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('actor_membership_id');
            $table->string('action', 40);
            $table->jsonb('data');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_membership_id'], 're_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK (status = 'demande'), ADD CONSTRAINT reservation_request_dates CHECK (ends_at > starts_at)");
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_reservation_event_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Reservation events are append-only'; END; $$ LANGUAGE plpgsql;");
        DB::unprepared('CREATE TRIGGER reservation_events_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON reservation_events FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_events');
        Schema::dropIfExists('reservations');
        DB::statement('DROP FUNCTION IF EXISTS reject_reservation_event_mutation()');
    }
};
