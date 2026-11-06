<?php

declare(strict_types=1);

$env = (string) env('APP_ENV', 'production');

return [
    // 'mysql' (default, required in production). 'sqlite' is a LOCAL DEV/TEST
    // HARNESS ONLY and is refused when APP_ENV=production.
    'driver' => $env !== 'production' && env('DB_DRIVER') === 'sqlite' ? 'sqlite' : 'mysql',
    'sqlite_path' => (string) env('DB_SQLITE_PATH', ''),
    'host' => (string) env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => (string) env('DB_DATABASE', 'mauro_library_dev'),
    'username' => (string) env('DB_USERNAME', ''),
    'password' => (string) env('DB_PASSWORD', ''),
    'socket' => (string) env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'timeout' => 5,
];
