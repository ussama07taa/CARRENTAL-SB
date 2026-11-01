<?php

return [
    // Agency binding remains server-owned. Visibility is vehicles.published only.
    'company_id' => (int) env('PUBLIC_SITE_COMPANY_ID', 0),
    'url' => env('PUBLIC_SITE_URL'),
];
