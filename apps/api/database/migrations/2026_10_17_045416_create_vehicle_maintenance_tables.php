<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_maintenance', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('kind', 20);
            $t->string('title', 150);
            $t->date('performed_on');
            $t->integer('kilometers')->nullable();
            $t->string('garage', 150)->nullable();
            $t->text('notes')->nullable();
            $t->bigInteger('cost_centimes');
            $t->date('payment_due_on')->nullable();
            $t->date('next_on')->nullable();
            $t->integer('next_km')->nullable();
            $t->boolean('in_garage')->default(false);
            $t->unsignedBigInteger('replaces_id')->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->string('path')->nullable();
            $t->string('mime', 50)->nullable();
            $t->string('sha256', 64)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->unique(['company_id', 'replaces_id']);
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->foreign(['company_id', 'replaces_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->index(['company_id', 'vehicle_id']);
        });
        Schema::create('maintenance_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('amount_centimes');
            $t->date('paid_on');
            $t->string('method', 20);
            $t->string('reference', 150)->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('maintenance_returns', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->date('returned_on');
            $t->string('note', 500);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'maintenance_id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_maintenance ADD CONSTRAINT maintenance_amount CHECK (cost_centimes >= 0 AND cost_centimes <= 1000000000), ADD CONSTRAINT maintenance_km CHECK ((kilometers IS NULL OR kilometers BETWEEN 0 AND 9999999) AND (next_km IS NULL OR next_km BETWEEN 1 AND 9999999)), ADD CONSTRAINT maintenance_dates CHECK (next_on IS NULL OR next_on > performed_on)');
        DB::statement('ALTER TABLE maintenance_payments ADD CONSTRAINT maintenance_payment_amount CHECK (amount_centimes > 0 AND amount_centimes <= 1000000000)');
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_maintenance_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'maintenance history is append-only'; END; $$ LANGUAGE plpgsql;");
        foreach (['vehicle_maintenance', 'maintenance_payments', 'maintenance_returns'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation();");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_returns');
        Schema::dropIfExists('maintenance_payments');
        Schema::dropIfExists('vehicle_maintenance');
        DB::statement('DROP FUNCTION IF EXISTS reject_maintenance_mutation()');
    }
};
