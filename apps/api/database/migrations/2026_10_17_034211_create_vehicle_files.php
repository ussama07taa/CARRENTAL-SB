<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('plate', 30);
            $t->string('plate_key', 30);
            $t->string('make', 60);
            $t->string('model', 80);
            $t->string('trim', 100)->nullable();
            $t->integer('year')->nullable();
            $t->string('fuel', 20)->nullable();
            $t->string('transmission', 20)->nullable();
            $t->string('color', 40)->nullable();
            $t->integer('seats')->nullable();
            $t->integer('doors')->nullable();
            $t->string('vin', 17)->nullable();
            $t->jsonb('equipment')->default('[]');
            $t->integer('current_odometer')->nullable();
            $t->date('current_odometer_date')->nullable();
            $t->integer('version')->default(1);
            $t->uuid('creation_key');
            $t->string('creation_hash', 64);
            $t->timestampsTz();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'plate_key']);
            $t->unique(['company_id', 'vin']);
            $t->unique(['company_id', 'creation_key']);
        });
        Schema::create('vehicle_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('action', 40);
            $t->jsonb('before')->nullable();
            $t->jsonb('after');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->index(['company_id', 'vehicle_id', 'id']);
        });
        Schema::create('vehicle_odometer_readings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->integer('kilometers');
            $t->date('recorded_on');
            $t->string('kind', 20);
            $t->string('reason', 500)->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'vehicle_id', 'id']);
        });
        Schema::create('vehicle_photos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('slot', 30);
            $t->integer('version');
            $t->uuid('upload_key');
            $t->string('disk', 50);
            $t->string('path');
            $t->string('mime', 50);
            $t->integer('size');
            $t->string('sha256', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->unique(['company_id', 'vehicle_id', 'slot', 'version']);
            $t->unique(['company_id', 'upload_key']);
            $t->unique(['company_id', 'id']);
        });
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicle_odometer_valid CHECK ((current_odometer IS NULL AND current_odometer_date IS NULL) OR (current_odometer BETWEEN 0 AND 9999999 AND current_odometer_date IS NOT NULL))');
        DB::statement("ALTER TABLE vehicle_odometer_readings ADD CONSTRAINT vehicle_reading_valid CHECK (kilometers BETWEEN 0 AND 9999999 AND kind IN ('initial','reading','correction') AND (kind <> 'correction' OR length(trim(reason)) > 0))");
        foreach (['vehicle_events', 'vehicle_odometer_readings', 'vehicle_photos'] as $table) {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_{$table}_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION '{$table} is append-only'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_{$table}_mutation();");
        }
    }

    public function down(): void
    {
        foreach (['vehicle_photos', 'vehicle_odometer_readings', 'vehicle_events'] as $table) {
            Schema::dropIfExists($table);
            DB::statement("DROP FUNCTION IF EXISTS reject_{$table}_mutation()");
        }
        Schema::dropIfExists('vehicles');
    }
};
