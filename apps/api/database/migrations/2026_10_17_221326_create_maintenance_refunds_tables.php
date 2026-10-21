<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_refunds', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('maintenance_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->bigInteger('amount_centimes');
            $t->date('received_on');
            $t->string('method', 20);
            $t->string('reason', 500);
            $t->string('reference', 150)->nullable();
            $t->string('path')->nullable();
            $t->string('mime', 50)->nullable();
            $t->string('sha256', 64)->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'maintenance_id'])->references(['company_id', 'id'])->on('vehicle_maintenance')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('maintenance_refund_allocations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('refund_id');
            $t->uuid('payment_key');
            $t->bigInteger('amount_centimes');
            $t->unique(['company_id', 'refund_id', 'payment_key'], 'mra_allocation_unique');
            $t->index(['company_id', 'payment_key']);
            $t->foreign(['company_id', 'refund_id'])->references(['company_id', 'id'])->on('maintenance_refunds')->restrictOnDelete();
            $t->foreign(['company_id', 'payment_key'])->references(['company_id', 'request_key'])->on('maintenance_payments')->restrictOnDelete();
        });
        foreach (['maintenance_refunds', 'maintenance_refund_allocations'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amount CHECK (amount_centimes BETWEEN 1 AND 1000000000)");
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_maintenance_mutation();");
        }
        DB::statement("ALTER TABLE maintenance_refunds ADD CONSTRAINT maintenance_refunds_method CHECK (method IN ('cash','transfer','card'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_refund_allocations');
        Schema::dropIfExists('maintenance_refunds');
    }
};
