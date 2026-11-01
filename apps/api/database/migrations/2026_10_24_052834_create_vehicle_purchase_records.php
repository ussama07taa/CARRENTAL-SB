<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_purchases', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('loan_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->string('seller', 150);
            $t->date('purchased_on');
            $t->date('down_payment_due_on');
            $t->bigInteger('price_centimes');
            $t->bigInteger('down_payment_centimes');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'loan_id']);
            $t->foreign(['company_id', 'loan_id'])->references(['company_id', 'id'])->on('vehicle_loans')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vp_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::create('vehicle_purchase_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('purchase_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->uuid('payment_key');
            $t->integer('version');
            $t->bigInteger('amount_centimes');
            $t->date('paid_on');
            $t->string('method', 20);
            $t->string('reference', 150)->nullable();
            $t->text('reason')->nullable();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'payment_key', 'version'], 'vpp_version_unique');
            $t->unique(['company_id', 'request_key'], 'vpp_request_unique');
            $t->index(['company_id', 'purchase_id']);
            $t->foreign(['company_id', 'purchase_id'], 'vpp_purchase_fk')->references(['company_id', 'id'])->on('vehicle_purchases')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'], 'vpp_actor_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE vehicle_purchases ADD CONSTRAINT vp_amounts CHECK (price_centimes BETWEEN 1 AND 1000000000 AND down_payment_centimes >= 0 AND down_payment_centimes < price_centimes)');
        DB::statement("ALTER TABLE vehicle_purchase_payments ADD CONSTRAINT vpp_amounts CHECK (version >= 1 AND amount_centimes BETWEEN 0 AND 1000000000 AND (version > 1 OR amount_centimes > 0) AND method IN ('cash','transfer','card') AND (version=1 OR COALESCE(length(trim(reason)),0)>0))");
        foreach (['vehicle_purchases', 'vehicle_purchase_payments'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_vehicle_loan_mutation();");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_purchase_payments');
        Schema::dropIfExists('vehicle_purchases');
    }
};
