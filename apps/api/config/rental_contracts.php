<?php

return [
    'verification_base_url' => env('CONTRACT_VERIFICATION_BASE_URL'),
    'tablet_signing_enabled' => env('APP_ENV') === 'demo' && env('DB_DATABASE') === 'bensebih_demo',
    'real_documents_enabled' => (bool) env('REAL_DOCUMENTS_ENABLED', false),
    'demo_only' => ! (bool) env('REAL_DOCUMENTS_ENABLED', false), 'model_version' => env('REAL_DOCUMENTS_ENABLED', false) ? 'rental-1' : 'demo-1',
    'node' => env('PDF_NODE_BINARY', '/opt/bensebih-toolchain/node/bin/node'),
    'npm' => env('PDF_NPM_BINARY', '/opt/bensebih-toolchain/node/bin/npm'),
    'chrome' => env('PDF_CHROME_BINARY'), 'no_sandbox' => (bool) env('PDF_NO_SANDBOX', false),
    'queue' => 'rental-pdfs-'.substr(hash('sha256', env('DB_DATABASE', 'default')), 0, 12),
];
