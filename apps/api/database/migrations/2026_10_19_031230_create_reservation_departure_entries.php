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
        Schema::create('reservation_departure_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('action', 40);
            $t->bigInteger('amount_centimes')->nullable();
            $t->unsignedBigInteger('receipt_id')->nullable();
            $t->string('method', 30)->nullable();
            $t->date('received_on')->nullable();
            $t->string('reference', 200)->nullable();
            $t->string('reason', 1000);
            $t->jsonb('data');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'departure_entry_parent_unique');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'receipt_id'], 'departure_receipt_fk')->references(['company_id', 'reservation_id', 'id'])->on('reservation_departure_entries')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE reservation_departure_entries ADD CHECK (action IN ('documents_checked','caution_set','caution_waived','caution_received','caution_verified')), ADD CHECK (amount_centimes IS NULL OR amount_centimes BETWEEN 1 AND 99999999999), ADD CHECK ((action IN ('caution_set','caution_received')) = (amount_centimes IS NOT NULL)), ADD CHECK ((action = 'caution_verified') = (receipt_id IS NOT NULL)), ADD CHECK ((action = 'caution_received') = (method IS NOT NULL AND received_on IS NOT NULL)), ADD CHECK (method IS NULL OR method IN ('especes','virement','carte_bloquee'))");
        DB::statement("CREATE UNIQUE INDEX departure_one_verification ON reservation_departure_entries (company_id, receipt_id) WHERE action = 'caution_verified'");
        DB::unprepared('CREATE TRIGGER reservation_departure_entries_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON reservation_departure_entries FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('reservation_departure_entries')->exists()) {
            throw new RuntimeException('Des vérifications ou cautions existent : conserver cette mise à jour.');
        }
        Schema::dropIfExists('reservation_departure_entries');
    }
};
