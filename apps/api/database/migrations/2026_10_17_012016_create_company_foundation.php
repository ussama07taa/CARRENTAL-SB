<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestampsTz();
        });
        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary_responsible')->default(false);
            $table->timestampsTz();
            $table->unique(['company_id', 'user_id']);
            $table->unique(['company_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX company_primary_responsible_unique ON company_memberships (company_id) WHERE is_primary_responsible');
        DB::statement('CREATE UNIQUE INDEX users_email_case_insensitive_unique ON users (lower(email))');
        Schema::create('company_setup_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('responsible_membership_id');
            $table->string('source');
            $table->string('operator_name');
            $table->timestampTz('created_at');
            $table->foreign(['company_id', 'responsible_membership_id'])
                ->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_company_setup_events() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Company setup history is append-only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER company_setup_events_immutable
            BEFORE UPDATE OR DELETE OR TRUNCATE ON company_setup_events
            FOR EACH STATEMENT EXECUTE FUNCTION protect_company_setup_events();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_setup_events');
        DB::statement('DROP FUNCTION IF EXISTS protect_company_setup_events()');
        Schema::dropIfExists('company_memberships');
        Schema::dropIfExists('companies');
        DB::statement('DROP INDEX IF EXISTS users_email_case_insensitive_unique');
    }
};
