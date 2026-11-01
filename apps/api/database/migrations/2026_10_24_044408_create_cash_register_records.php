<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cash_entries', 'cash_counts'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('actor_membership_id');
                $t->uuid('request_key');
                $t->string('request_hash', 64);
                $t->timestampTz('created_at')->useCurrent();
                $t->unique(['company_id', 'id']);
                $t->unique(['company_id', 'request_key']);
                $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
                $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
                if ($name === 'cash_entries') {
                    $t->uuid('entry_key');
                    $t->integer('version');
                    $t->string('kind', 24);
                    $t->date('occurred_on');
                    $t->bigInteger('amount_centimes');
                    $t->string('reason', 500);
                    $t->unique(['company_id', 'entry_key', 'version']);
                } else {
                    $t->date('counted_on');
                    $t->bigInteger('expected_centimes');
                    $t->bigInteger('counted_centimes');
                    $t->bigInteger('difference_centimes');
                    $t->string('ledger_hash', 64);
                    $t->jsonb('snapshot');
                    $t->string('note', 500);
                    $t->index(['company_id', 'counted_on']);
                }
            });
        }
        DB::statement("ALTER TABLE cash_entries ADD CHECK (version>0 AND amount_centimes BETWEEN 0 AND 99999999999 AND kind IN ('opening','cash_in','cash_out','bank_deposit','bank_withdrawal'))");
        DB::statement("CREATE UNIQUE INDEX cash_single_opening ON cash_entries(company_id) WHERE kind='opening' AND version=1");
        DB::statement('ALTER TABLE cash_counts ADD CHECK(counted_centimes BETWEEN 0 AND 99999999999 AND difference_centimes=counted_centimes-expected_centimes)');
        foreach (['cash_entries', 'cash_counts'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON $t FOR EACH STATEMENT EXECUTE FUNCTION reject_expense_mutation()");
        }
    }

    public function down(): void
    {
        if (DB::table('cash_entries')->exists() || DB::table('cash_counts')->exists()) {
            throw new RuntimeException('Conserver les ouvertures et comptages de caisse.');
        }
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('cash_entries');
    }
};
