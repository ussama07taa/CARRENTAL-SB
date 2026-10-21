<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('kind', 30);
            $t->integer('version');
            $t->string('change_kind', 20);
            $t->string('reason', 500)->nullable();
            $t->date('effective_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('provider', 120)->nullable();
            $t->string('policy_number', 100)->nullable();
            $t->integer('tax_year')->nullable();
            $t->date('paid_on')->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->string('disk', 50)->nullable();
            $t->string('path')->nullable();
            $t->string('mime', 50)->nullable();
            $t->integer('size')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->unique(['company_id', 'vehicle_id', 'kind', 'version'], 'vehicle_doc_version');
            $t->unique(['company_id', 'request_key']);
            $t->unique(['company_id', 'id']);
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_vehicle_documents_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'vehicle_documents is append-only'; END; $$ LANGUAGE plpgsql;
        CREATE TRIGGER vehicle_documents_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON vehicle_documents FOR EACH STATEMENT EXECUTE FUNCTION reject_vehicle_documents_mutation();");
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
        DB::statement('DROP FUNCTION IF EXISTS reject_vehicle_documents_mutation()');
    }
};
