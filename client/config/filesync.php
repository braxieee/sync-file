<?php

return [
    'server_url' => env('SERVER_URL', ''),
    'api_token' => env('CLIENT_API_TOKEN', ''),
    'sync_folder' => env('SYNC_FOLDER', storage_path('app/private/files')),
    'poll_interval' => env('POLL_INTERVAL', 15),
];