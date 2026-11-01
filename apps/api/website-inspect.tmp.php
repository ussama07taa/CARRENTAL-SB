<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.demo');
$app->make(Kernel::class)->bootstrap();
echo 'Database: '.DB::connection()->getDatabaseName().PHP_EOL;
echo json_encode(DB::table('vehicles')->where('plate', 'like', 'DEMO-FLEET%')->get(['id', 'company_id', 'make', 'model']), JSON_PRETTY_PRINT).PHP_EOL;
