<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_real_postgresql_and_redis_are_ready(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bensebih_test', DB::connection()->getDatabaseName());
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
            'status' => 'ok', 'checks' => ['database' => true, 'redis' => true],
        ]);
    }

    public function test_dependency_failure_is_not_reported_as_success_or_leaked(): void
    {
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('sensitive connection details'));
        $this->getJson('/api/v1/health')->assertStatus(503)
            ->assertJsonPath('checks.redis', false)->assertDontSee('sensitive connection details');
    }
}
