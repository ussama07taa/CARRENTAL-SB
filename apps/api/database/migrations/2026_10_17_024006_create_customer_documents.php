<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('actor_membership_id');
            $table->string('slot', 30);
            $table->unsignedInteger('version');
            $table->uuid('upload_key');
            $table->string('disk', 50);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $table->unique(['company_id', 'customer_id', 'slot', 'version'], 'customer_document_version_unique');
            $table->unique(['company_id', 'upload_key']);
            $table->unique(['company_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_customer_document_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'customer_documents is append-only'; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER customer_documents_immutable BEFORE UPDATE OR DELETE OR TRUNCATE
            ON customer_documents FOR EACH STATEMENT EXECUTE FUNCTION reject_customer_document_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
        DB::statement('DROP FUNCTION IF EXISTS reject_customer_document_mutation()');
    }
};
