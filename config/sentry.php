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
    'face' => [
        'match_threshold' => (float) env('FACE_MATCH_THRESHOLD', 0.6),
        'ambiguity_margin' => (float) env('FACE_AMBIGUITY_MARGIN', 0.08),
        'min_enrolled_poses' => (int) env('FACE_MIN_ENROLLED_POSES', 1),
    ],
];
