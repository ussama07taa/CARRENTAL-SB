<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Fresh disposable databases support append-only migrations without unsafe rollback. */
trait IsolatedDatabaseMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'bensebih_test') {
            throw new \RuntimeException('Disposable bensebih_test database required.');
        }
        // Invalidate before setup too, so a failing setup cannot leak into a lazy test.
        RefreshDatabaseState::$migrated = false;
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function () {
            // The next DatabaseMigrations or LazilyRefreshDatabase test starts fresh.
            // Never invoke irreversible production down() methods for test cleanup.
            RefreshDatabaseState::$migrated = false;
        });
    }
}
