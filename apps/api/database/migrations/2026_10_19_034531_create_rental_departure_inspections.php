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
        Schema::create('rental_departure_inspections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->date('inspected_on');
            $t->unsignedInteger('kilometers');
            $t->unsignedTinyInteger('fuel_eighths');
            $t->string('condition', 40);
            $t->text('notes')->nullable();
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'rental_inspection_parent_unique');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'previous_id'], 'rental_inspection_previous_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_departure_inspections')->restrictOnDelete();
        });
        Schema::create('rental_inspection_photos', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('inspection_id');
            $t->string('disk', 40);
            $t->string('path');
            $t->string('mime', 40);
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->string('caption', 120)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'reservation_id', 'inspection_id'], 'rental_inspection_photo_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_departure_inspections')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE rental_departure_inspections ADD CHECK (kilometers BETWEEN 0 AND 9999999), ADD CHECK (fuel_eighths BETWEEN 0 AND 8), ADD CHECK (condition IN ('bon','dommages_existants','anomalie_bloquante')), ADD CHECK (condition='bon' OR (notes IS NOT NULL AND length(trim(notes)) >= 3))");
        foreach (['rental_departure_inspections', 'rental_inspection_photos'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('rental_departure_inspections')->exists()) {
            throw new RuntimeException('Des inspections existent : conserver cette mise à jour.');
        }
        Schema::dropIfExists('rental_inspection_photos');
        Schema::dropIfExists('rental_departure_inspections');
    }
};
