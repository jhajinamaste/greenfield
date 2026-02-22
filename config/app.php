<?php

declare(strict_types=1);

return [
    'name' => 'Greenfield ERP',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', 'false') === 'true',
    'url' => env('APP_URL', ''),
    'key' => env('APP_KEY', ''),
    'session_timeout_minutes' => (int) env('SESSION_TIMEOUT_MINUTES', '180'),
    'secure_cookies' => env('SECURE_COOKIES', 'true') === 'true',
    'health_public' => env('HEALTH_PUBLIC', 'false') === 'true',
    'password_algo' => env('PASSWORD_ALGO', 'argon2id'),
    'accounting_strict_mode' => in_array(
        strtolower((string) env(
            'ACCOUNTING_STRICT_MODE',
            env('APP_ENV', 'production') === 'production' ? 'true' : 'false'
        )),
        ['1', 'true', 'yes', 'on'],
        true
    ),
];
