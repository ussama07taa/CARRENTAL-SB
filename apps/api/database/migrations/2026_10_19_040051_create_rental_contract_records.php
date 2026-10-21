<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_contract_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->jsonb('snapshot');
            $t->string('fingerprint', 64);
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'contract_parent_unique');
            $t->unique(['company_id', 'reservation_id', 'version'], 'contract_version_unique');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'previous_id'], 'contract_previous_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_contract_versions')->restrictOnDelete();
        });
        Schema::create('rental_contract_files', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('contract_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('kind', 10);
            $t->string('path');
            $t->string('mime', 40);
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'contract_id', 'id'], 'contract_file_parent_unique');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id', 'contract_id'], 'contract_file_version_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_contract_versions')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('rental_contract_verifications', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('contract_id');
            $t->unsignedBigInteger('file_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'file_id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id', 'contract_id', 'file_id'], 'contract_verified_file_fk')->references(['company_id', 'reservation_id', 'contract_id', 'id'])->on('rental_contract_files')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('rental_contract_render_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('contract_id');
            $t->string('action', 20);
            $t->timestampTz('created_at');
            $t->foreign(['company_id', 'contract_id'])->references(['company_id', 'id'])->on('rental_contract_versions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE rental_contract_versions ADD CHECK(version>0)');
        DB::statement("ALTER TABLE rental_contract_files ADD CHECK(kind IN ('original','signed'))");
        DB::statement("CREATE UNIQUE INDEX contract_one_original ON rental_contract_files(company_id,contract_id) WHERE kind='original'");
        DB::statement("ALTER TABLE rental_contract_render_events ADD CHECK(action IN ('requested','failed'))");
        foreach (['rental_contract_versions', 'rental_contract_files', 'rental_contract_verifications', 'rental_contract_render_events'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
    }

    public function down(): void
    {
        if (DB::table('rental_contract_versions')->exists()) {
            throw new RuntimeException('Conserver les contrats et leurs originaux.');
        }foreach (['rental_contract_render_events', 'rental_contract_verifications', 'rental_contract_files', 'rental_contract_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
