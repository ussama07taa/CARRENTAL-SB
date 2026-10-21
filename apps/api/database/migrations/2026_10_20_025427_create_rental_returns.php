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
        Schema::table('rental_pickups', fn (Blueprint $t) => $t->unique(['company_id', 'reservation_id', 'id'], 'pickup_return_parent_unique'));
        Schema::create('rental_returns', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('pickup_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->timestampTz('returned_at');
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'return_parent_unique');
            $t->unique(['company_id', 'reservation_id']);
            $t->unique(['company_id', 'request_key']);
            foreach (['reservation_id' => 'reservations', 'vehicle_id' => 'vehicles', 'actor_membership_id' => 'company_memberships'] as $column => $parent) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($parent)->restrictOnDelete();
            }
            $t->foreign(['company_id', 'reservation_id', 'pickup_id'], 'return_pickup_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_pickups')->restrictOnDelete();
        });
        Schema::create('rental_return_inspections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('return_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->unsignedInteger('kilometers');
            $t->unsignedTinyInteger('fuel_eighths');
            $t->string('condition', 40);
            $t->text('notes')->nullable();
            $t->string('signature_state', 30);
            $t->string('signature_reason', 1000)->nullable();
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'return_id', 'id'], 'return_inspection_parent_unique');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id', 'return_id'], 'return_inspection_return_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_returns')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'return_id', 'previous_id'], 'return_inspection_previous_fk')->references(['company_id', 'reservation_id', 'return_id', 'id'])->on('rental_return_inspections')->restrictOnDelete();
        });
        Schema::create('rental_return_files', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('return_id');
            $t->unsignedBigInteger('inspection_id');
            $t->string('kind', 30);
            $t->string('disk', 40);
            $t->string('path');
            $t->string('mime', 40);
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->string('caption', 120)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'reservation_id', 'return_id', 'inspection_id'], 'return_file_inspection_fk')->references(['company_id', 'reservation_id', 'return_id', 'id'])->on('rental_return_inspections')->restrictOnDelete();
        });
        Schema::create('rental_return_readiness', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('return_id');
            $t->unsignedBigInteger('inspection_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id', 'return_id', 'inspection_id'], 'return_readiness_inspection_fk')->references(['company_id', 'reservation_id', 'return_id', 'id'])->on('rental_return_inspections')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE rental_return_inspections ADD CHECK (kilometers BETWEEN 0 AND 9999999), ADD CHECK(fuel_eighths BETWEEN 0 AND 8), ADD CHECK(condition IN ('bon','dommages_existants','anomalie_bloquante')), ADD CHECK(signature_state IN ('signed','pending','client_refused','client_absent'))");
        DB::statement("ALTER TABLE rental_return_files ADD CHECK(kind IN ('photo','video','client_signature','agency_signature'))");
        foreach (['rental_returns', 'rental_return_inspections', 'rental_return_files', 'rental_return_readiness'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_request_status, DROP CONSTRAINT reservation_confirmed_fields');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK(status IN ('demande','temporaire','confirmee','en_location','retournee')), ADD CONSTRAINT reservation_confirmed_fields CHECK(status NOT IN ('confirmee','en_location','retournee') OR (confirmed_at IS NOT NULL AND frozen_total_centimes IS NOT NULL AND frozen_total_centimes>0 AND blocked_until IS NOT NULL AND preparation_minutes IS NOT NULL AND blocked_until>=ends_at))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('rental_returns')->exists() || DB::table('reservations')->where('status', 'retournee')->exists()) {
            throw new RuntimeException('Conserver les retours enregistrés.');
        }
        foreach (['rental_return_readiness', 'rental_return_files', 'rental_return_inspections', 'rental_returns'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('rental_pickups', fn (Blueprint $t) => $t->dropUnique('pickup_return_parent_unique'));
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_request_status, DROP CONSTRAINT reservation_confirmed_fields');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK(status IN ('demande','temporaire','confirmee','en_location')), ADD CONSTRAINT reservation_confirmed_fields CHECK(status NOT IN ('confirmee','en_location') OR (confirmed_at IS NOT NULL AND frozen_total_centimes IS NOT NULL AND frozen_total_centimes>0 AND blocked_until IS NOT NULL AND preparation_minutes IS NOT NULL AND blocked_until>=ends_at))");
    }
};
