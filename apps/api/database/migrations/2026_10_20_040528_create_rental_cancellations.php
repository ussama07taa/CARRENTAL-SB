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
        Schema::create('rental_cancellations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('final_total_centimes');
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE rental_cancellations ADD CHECK(final_total_centimes BETWEEN 0 AND 99999999999)');
        DB::unprepared('CREATE TRIGGER rental_cancellations_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_cancellations FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_request_status');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK(status IN ('demande','temporaire','confirmee','en_location','retournee','annulee'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('rental_cancellations')->exists()) {
            throw new RuntimeException('Conserver les annulations enregistrées.');
        }
        Schema::dropIfExists('rental_cancellations');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_request_status');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK(status IN ('demande','temporaire','confirmee','en_location','retournee'))");
    }
};
