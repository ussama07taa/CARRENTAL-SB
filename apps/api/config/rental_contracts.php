<?php

return [
    'tablet_signing_enabled' => env('APP_ENV') === 'demo' && env('DB_DATABASE') === 'bensebih_demo',
    'demo_only' => true, 'model_version' => 'demo-1',
    'node' => env('PDF_NODE_BINARY', '/opt/bensebih-toolchain/node/bin/node'),
    'npm' => env('PDF_NPM_BINARY', '/opt/bensebih-toolchain/node/bin/npm'),
    'chrome' => env('PDF_CHROME_BINARY'), 'no_sandbox' => (bool) env('PDF_NO_SANDBOX', false),
    'queue' => 'rental-pdfs-'.substr(hash('sha256', env('DB_DATABASE', 'default')), 0, 12),
];
