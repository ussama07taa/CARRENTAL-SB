<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t): void {
            $t->unsignedBigInteger('actor_membership_id')->nullable()->change();
            $t->string('source', 20)->default('agency');
            $t->string('language', 2)->default('fr');
            $t->jsonb('website_request')->nullable();
        });
        foreach (['reservation_events', 'customer_events'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('actor_membership_id')->nullable()->change());
        }
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT website_request_origin CHECK ((source='agency' AND actor_membership_id IS NOT NULL AND website_request IS NULL) OR (source='website' AND actor_membership_id IS NULL AND website_request IS NOT NULL)), ADD CONSTRAINT website_language CHECK (language IN ('fr','ar','en','es'))");
        DB::statement("ALTER TABLE reservation_events ADD CONSTRAINT public_event_actor CHECK (actor_membership_id IS NOT NULL OR action='website_request_created')");
        DB::statement("ALTER TABLE customer_events ADD CONSTRAINT public_customer_actor CHECK (actor_membership_id IS NOT NULL OR action='website_created')");
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve website request history; use a reviewed forward migration.');
    }
};
