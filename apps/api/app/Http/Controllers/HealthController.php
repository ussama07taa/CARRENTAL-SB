<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => false, 'redis' => false];
        try {
            $checks['database'] = DB::connection()->getDriverName() === 'pgsql'
                && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'btree_gist') AS installed")->installed;
        } catch (Throwable) {
            // Readiness must not expose connection strings or SQL errors.
        }
        try {
            $checks['redis'] = in_array(Redis::connection()->ping(), [true, 'PONG', '+PONG'], true);
        } catch (Throwable) {
            // A dependency outage is unavailable, not a successful check.
        }
        $ready = ! in_array(false, $checks, true);

        return response()->json(['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503);
    }
}
