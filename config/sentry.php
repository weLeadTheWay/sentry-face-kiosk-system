<?php

return [
    'pagination' => env('APP_PAGINATION_SIZE', 50),
    'session_timeout' => env('APP_SESSION_TIMEOUT', 120),
    'password_min_length' => 8,
    'max_login_attempts' => 5,
    'lockout_duration' => 900,
];
