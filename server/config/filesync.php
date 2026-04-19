<?php

return [
    'webhook_url'       => env('WEBHOOK_URL', ''),
    'webhook_secret'    => env('WEBHOOK_SECRET', ''),
    'stale_job_minutes' => env('STALE_JOB_MINUTES', 30),
    'max_upload_bytes'  => env('MAX_UPLOAD_BYTES', 0),
];