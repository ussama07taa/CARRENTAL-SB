<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone', 20);
            $table->string('secondary_phone', 20)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('language', 2)->default('fr');
            $table->string('identity_type', 10)->nullable();
            $table->string('identity_number', 50)->nullable();
            $table->string('licence_number', 50)->nullable();
            $table->date('licence_expires_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('creation_key');
            $table->string('creation_hash', 64);
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'creation_key']);
            $table->unique(['company_id', 'identity_type', 'identity_number']);
            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'last_name', 'first_name']);
        });
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customer_identity_pair CHECK ((identity_type IS NULL) = (identity_number IS NULL))');
        Schema::create('customer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('actor_membership_id');
            $table->string('action', 20);
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $table->index(['company_id', 'customer_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_customer_event_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'customer_events is append-only'; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER customer_events_immutable BEFORE UPDATE OR DELETE OR TRUNCATE
            ON customer_events FOR EACH STATEMENT EXECUTE FUNCTION reject_customer_event_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_events');
        Schema::dropIfExists('customers');
        DB::statement('DROP FUNCTION IF EXISTS reject_customer_event_mutation()');
    }
};
