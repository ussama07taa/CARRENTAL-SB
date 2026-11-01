<?php

namespace App\Support\Finance;

use Illuminate\Support\Facades\DB;

final class FinancialSnapshot
{
    /** Keep totals, details and pagination on one committed PostgreSQL snapshot. */
    public static function read(callable $read): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $read();
        }

        return DB::transaction(function () use ($read) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read();
        });
    }
}
