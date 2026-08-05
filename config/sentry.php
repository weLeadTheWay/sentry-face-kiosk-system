<?php

return [
    'pagination' => env('APP_PAGINATION_SIZE', 50),
    'session_timeout' => env('APP_SESSION_TIMEOUT', 120),
    'password_min_length' => 8,
    'max_login_attempts' => 5,
    'lockout_duration' => 900,
    'sync_api_key' => env('SYNC_API_KEY'),
    'google' => [
        'credentials_path' => 'credentials/service-account.json',
        'visitors_spreadsheet_id' => env('SENTRY_VISITORS_ID'),
    ],
];
