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
        foreach (['rental_fuel_charges', 'rental_fuel_payments', 'rental_fuel_verifications'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('reservation_id');
                $t->unsignedBigInteger('actor_membership_id');
                $t->uuid('request_key');
                $t->string('request_hash', 64);
                $t->string('reason', 1000);
                $t->timestampTz('created_at');
                $t->unique(['company_id', 'id']);
                $t->unique(['company_id', 'request_key']);
                $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
                $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
                if ($name === 'rental_fuel_charges') {
                    $t->unsignedBigInteger('return_id');
                    $t->unsignedBigInteger('inspection_id');
                    $t->bigInteger('amount_centimes');
                    $t->string('ticket_reference', 200);
                    $t->jsonb('snapshot');
                    $t->string('ticket_path')->nullable();
                    $t->string('ticket_mime', 40)->nullable();
                    $t->unsignedInteger('ticket_size')->nullable();
                    $t->string('ticket_sha256', 64)->nullable();
                    $t->unique(['company_id', 'reservation_id']);
                    $t->unique(['company_id', 'reservation_id', 'id'], 'fuel_charge_parent_unique');
                    $t->foreign(['company_id', 'reservation_id', 'return_id', 'inspection_id'], 'fuel_charge_inspection_fk')->references(['company_id', 'reservation_id', 'return_id', 'id'])->on('rental_return_inspections')->restrictOnDelete();
                } else {
                    $t->unsignedBigInteger('charge_id');
                    $t->foreign(['company_id', 'reservation_id', 'charge_id'], $name.'_charge_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_fuel_charges')->restrictOnDelete();
                    if ($name === 'rental_fuel_payments') {
                        $t->bigInteger('amount_centimes');
                        $t->string('method', 20);
                        $t->date('received_on');
                        $t->string('reference', 200)->nullable();
                        $t->unique(['company_id', 'reservation_id', 'charge_id', 'id'], 'fuel_payment_parent_unique');
                    } else {
                        $t->unsignedBigInteger('payment_id');
                        $t->unique(['company_id', 'payment_id']);
                        $t->foreign(['company_id', 'reservation_id', 'charge_id', 'payment_id'], 'fuel_verify_payment_fk')->references(['company_id', 'reservation_id', 'charge_id', 'id'])->on('rental_fuel_payments')->restrictOnDelete();
                    }
                }
            });
            DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
        DB::statement('ALTER TABLE rental_fuel_charges ADD CHECK(amount_centimes BETWEEN 1 AND 99999999999)');
        DB::statement("ALTER TABLE rental_fuel_payments ADD CHECK(amount_centimes BETWEEN 1 AND 99999999999), ADD CHECK(method IN ('especes','carte','virement'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('rental_fuel_charges')->exists()) {
            throw new RuntimeException('Conserver les régularisations et reçus de carburant.');
        }
        foreach (['rental_fuel_verifications', 'rental_fuel_payments', 'rental_fuel_charges'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
