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
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->primary();
            $table->unsignedInteger('preparation_minutes')->default(120);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT preparation_minutes_range CHECK (preparation_minutes BETWEEN 0 AND 10080)');
        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestampTz('hold_expires_at')->nullable();
            $table->timestampTz('blocked_until')->nullable();
            $table->unsignedInteger('preparation_minutes')->nullable();
            $table->index(['company_id', 'status', 'hold_expires_at']);
        });
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_request_status');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK (status IN ('demande', 'temporaire')), ADD CONSTRAINT reservation_hold_fields CHECK (status <> 'temporaire' OR (hold_expires_at IS NOT NULL AND blocked_until IS NOT NULL AND preparation_minutes IS NOT NULL AND preparation_minutes >= 0 AND blocked_until >= ends_at))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_hold_no_overlap EXCLUDE USING gist (company_id WITH =, vehicle_id WITH =, tstzrange(starts_at, blocked_until, '[)') WITH &&) WHERE (status = 'temporaire')");
        Schema::create('reservation_hold_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('actor_membership_id');
            $table->uuid('request_key');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->unique(['company_id', 'request_key']);
            $table->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_membership_id'], 'rhr_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::unprepared('CREATE TRIGGER reservation_hold_requests_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON reservation_hold_requests FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('reservations')->where('status', 'temporaire')->exists()) {
            throw new RuntimeException('Libérez les réservations temporaires avant de retirer cette fonctionnalité.');
        }
        Schema::dropIfExists('reservation_hold_requests');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_hold_no_overlap, DROP CONSTRAINT reservation_hold_fields, DROP CONSTRAINT reservation_request_status');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK (status = 'demande')");
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status', 'hold_expires_at']);
            $table->dropColumn(['hold_expires_at', 'blocked_until', 'preparation_minutes']);
        });
        Schema::dropIfExists('company_settings');
    }
};
