<?php

return [
    'server_url'    => env('SERVER_URL', 'http://127.0.0.1:8000/'),
    'api_token'     => env('CLIENT_API_TOKEN', 'gbeIph6w0gJTyh500WxuukOhNfa1lOOK32U2yZ7h9YOp0Z7GiBT7qEX7uSPU'),
    'file_path'     => env('SYNC_FILE_PATH', storage_path('app/private/files/test.txt')),
    'poll_interval' => env('POLL_INTERVAL', 15),
];