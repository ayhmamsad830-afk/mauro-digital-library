<?php

declare(strict_types=1);

$secure = env('SESSION_SECURE_COOKIE', 'auto');

return [
    'name' => (string) env('SESSION_NAME', 'dl_session'),
    'lifetime' => (int) env('SESSION_LIFETIME', 7200),
    // 'auto' => Secure flag only on HTTPS requests; true/false to force.
    'secure' => $secure === 'auto' ? 'auto' : (bool) $secure,
    'save_path' => BASE_PATH . '/storage/sessions',
];
