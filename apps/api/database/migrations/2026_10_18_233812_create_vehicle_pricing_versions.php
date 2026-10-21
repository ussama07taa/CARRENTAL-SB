<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_pricing_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->string('reason', 1000);
            $t->jsonb('configuration');
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('vehicle_pricing_rates', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('version_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->uuid('season_key')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->bigInteger('daily_centimes');
            $t->unsignedInteger('minimum_days')->default(0);
            $t->boolean('active')->default(true);
            $t->foreign(['company_id', 'version_id'])->references(['company_id', 'id'])->on('vehicle_pricing_versions')->restrictOnDelete();
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_pricing_rates ADD CHECK(daily_centimes BETWEEN 1 AND 999999999), ADD CHECK(minimum_days BETWEEN 0 AND 365), ADD CHECK((season_key IS NULL AND starts_on IS NULL AND ends_on IS NULL) OR (season_key IS NOT NULL AND starts_on IS NOT NULL AND ends_on IS NOT NULL AND ends_on>=starts_on))');
        DB::statement('CREATE UNIQUE INDEX vehicle_normal_rate_unique ON vehicle_pricing_rates(company_id,version_id,vehicle_id) WHERE season_key IS NULL');
        DB::statement("ALTER TABLE vehicle_pricing_rates ADD CONSTRAINT vehicle_season_no_overlap EXCLUDE USING gist(company_id WITH =,version_id WITH =,vehicle_id WITH =,daterange(starts_on,ends_on,'[]') WITH &&) WHERE (active AND season_key IS NOT NULL)");
        foreach (['vehicle_pricing_versions', 'vehicle_pricing_rates'] as $name) {
            DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
    }

    public function down(): void
    {
        Schema::drop('vehicle_pricing_rates');
        Schema::drop('vehicle_pricing_versions');
    }
};
