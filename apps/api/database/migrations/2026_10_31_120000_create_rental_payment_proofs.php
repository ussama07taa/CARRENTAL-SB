<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_payment_proofs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('payment_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->uuid('request_key');
            $t->string('path');
            $t->string('mime', 80);
            $t->string('checksum', 64);
            $t->unsignedInteger('size');
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->foreign(['company_id', 'payment_id'])->references(['company_id', 'id'])->on('rental_payments')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::unprepared('CREATE TRIGGER rental_payment_proofs_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_payment_proofs FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
    }

    public function down(): void
    {
        if (DB::table('rental_payment_proofs')->exists()) {
            throw new RuntimeException('Des justificatifs existent : conserver cette mise à jour.');
        }
        Schema::dropIfExists('rental_payment_proofs');
    }
};
