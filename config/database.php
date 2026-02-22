<?php

declare(strict_types=1);

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', '3306'),
    'name' => env('DB_NAME', 'greenfield'),
    'user' => env('DB_USER', 'root'),
    'pass' => env('DB_PASS', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
