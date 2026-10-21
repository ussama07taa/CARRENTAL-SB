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
        Schema::table('rental_payments', fn (Blueprint $t) => $t->unique(['company_id', 'reservation_id', 'id'], 'rent_payment_settlement_parent'));
        Schema::table('rental_fuel_payments', fn (Blueprint $t) => $t->unique(['company_id', 'reservation_id', 'id'], 'fuel_payment_settlement_parent'));
        Schema::create('rental_settlement_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('kind', 40);
            $t->string('scope', 20);
            $t->bigInteger('amount_centimes');
            $t->unsignedBigInteger('rent_payment_id')->nullable();
            $t->unsignedBigInteger('fuel_payment_id')->nullable();
            $t->unsignedBigInteger('deposit_receipt_id')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('method', 30)->nullable();
            $t->date('occurred_on');
            $t->string('reference', 200)->nullable();
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'settlement_parent_unique');
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            foreach (['rent_payment_id' => 'rental_payments', 'fuel_payment_id' => 'rental_fuel_payments', 'deposit_receipt_id' => 'reservation_departure_entries', 'source_id' => 'rental_settlement_entries'] as $column => $table) {
                $t->foreign(['company_id', 'reservation_id', $column], 'settlement_'.$column.'_fk')->references(['company_id', 'reservation_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE rental_settlement_entries ADD CHECK(amount_centimes BETWEEN 0 AND 99999999999), ADD CHECK(scope IN ('rent','fuel','deposit','damage')), ADD CHECK(kind IN ('fuel_amount','payment_void','deposit_return','deposit_allocate','rent_total','refund_approved','refund_paid', 'refund_cancelled','damage_amount'))");
        DB::statement("CREATE UNIQUE INDEX settlement_one_rent_void ON rental_settlement_entries(company_id,rent_payment_id) WHERE kind='payment_void' AND rent_payment_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX settlement_one_fuel_void ON rental_settlement_entries(company_id,fuel_payment_id) WHERE kind='payment_void' AND fuel_payment_id IS NOT NULL");
        DB::unprepared('CREATE TRIGGER rental_settlement_entries_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_settlement_entries FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('rental_settlement_entries')->exists()) {
            throw new RuntimeException('Conserver les règlements et les décisions enregistrés.');
        }
        Schema::dropIfExists('rental_settlement_entries');
        Schema::table('rental_payments', fn (Blueprint $t) => $t->dropUnique('rent_payment_settlement_parent'));
        Schema::table('rental_fuel_payments', fn (Blueprint $t) => $t->dropUnique('fuel_payment_settlement_parent'));
    }
};
