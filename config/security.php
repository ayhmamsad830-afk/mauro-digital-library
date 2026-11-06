<?php

declare(strict_types=1);

return [
    // Only enable behind a trusted reverse proxy / load balancer that
    // overwrites X-Forwarded-Proto. Otherwise clients could spoof HTTPS.
    'trust_proxy_headers' => (bool) env('TRUST_PROXY_HEADERS', false),
    // Client IP behind a reverse proxy: only when REMOTE_ADDR is one of these
    // addresses/CIDRs is X-Forwarded-For consulted (right-most untrusted hop).
    // Empty = always use REMOTE_ADDR. Example: "10.0.0.0/8,127.0.0.1"
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'hsts' => true,

    // Starting rate limits from spec §13.5 (enforced from Phase 4/6).
    'rate_limits' => [
        'google_signin' => ['max' => 5, 'window' => 900],
        'search' => ['max' => 60, 'window' => 60],
        'reading_progress' => ['max' => 30, 'window' => 60],
        'reviews' => ['max' => 5, 'window' => 60],
        'reader_pages' => ['max' => (int) env('READER_PAGES_PER_MINUTE', 120), 'window' => 60],
        'reader_thumbs' => ['max' => (int) env('READER_THUMBS_PER_MINUTE', 600), 'window' => 60],
        'reader_sessions' => ['max' => (int) env('READER_SESSIONS_PER_10MIN', 60), 'window' => 600],
        'admin_upload' => ['max' => 10, 'window' => 600],
    ],
];
