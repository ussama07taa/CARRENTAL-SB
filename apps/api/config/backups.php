<?php
return [
    // Explicitly bind local recovery metadata to one agency. Never expose archives through HTTP.
    'directory' => env('BACKUP_STATUS_DIRECTORY'),
    'runner' => env('BACKUP_RUNNER'),
    'company_id' => (int) env('BACKUP_STATUS_COMPANY_ID', 0),
];
