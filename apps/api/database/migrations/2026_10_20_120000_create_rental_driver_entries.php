<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_driver_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('action', 20);
            $t->jsonb('snapshot');
            $t->string('reason', 1000);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'reservation_id', 'id']);
            foreach (['reservation_id' => 'reservations', 'customer_id' => 'customers', 'actor_membership_id' => 'company_memberships'] as $column => $table) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE rental_driver_entries ADD CHECK (action IN ('assigned','verified','removed')), ADD CHECK ((action = 'removed') = (customer_id IS NULL))");
        DB::unprepared('CREATE TRIGGER rental_driver_entries_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_driver_entries FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
    }

    public function down(): void
    {
        if (DB::table('rental_driver_entries')->exists()) {
            throw new RuntimeException('Conserver l’historique des conducteurs.');
        }
        Schema::dropIfExists('rental_driver_entries');
    }
};
