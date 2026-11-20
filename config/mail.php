<?php

declare(strict_types=1);

/*
 * Outgoing e-mail (password recovery, contact notifications).
 *
 * MAIL_DRIVER:
 *   disabled (default) — nothing is ever sent; features that need e-mail say so honestly.
 *   smtp               — deliver through the SMTP server below (credentials only in .env).
 *   log                — development only: the message is recorded as "logged" (body never
 *                        written to the log); refused when APP_ENV=production.
 */
return [
    'driver' => (string) env('MAIL_DRIVER', 'disabled'),
    'host' => (string) env('MAIL_HOST', ''),
    'port' => (int) env('MAIL_PORT', 587),
    // tls = STARTTLS on 587, ssl = implicit TLS on 465. Plain text is never allowed.
    'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),
    'username' => (string) env('MAIL_USERNAME', ''),
    'password' => (string) env('MAIL_PASSWORD', ''),
    'from_address' => (string) env('MAIL_FROM_ADDRESS', ''),
    'from_name' => (string) env('MAIL_FROM_NAME', 'MAURO'),
    'timeout' => (int) env('MAIL_TIMEOUT', 15),
];
