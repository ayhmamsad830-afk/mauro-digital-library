<?php

declare(strict_types=1);

return [
    'name' => (string) env('APP_NAME', 'MAURO'),
    // Official brand (Phase 3). The Arabic name is shown in the Arabic UI,
    // the Latin name in English and inside server-side watermarks (GD cannot shape Arabic).
    'brand' => [
        'ar' => (string) env('APP_NAME_AR', 'مُعِين خيري'),
        'en' => (string) env('APP_NAME_EN', 'MAURO'),
    ],
    // Official logo: drop the original file here (svg|png|webp) — it is displayed
    // unmodified (no recolouring/cropping; aspect ratio preserved).
    'logo_dir' => 'assets/images/brand',
    // true if the logo artwork already contains the written name (then the name is not repeated as text).
    'logo_has_wordmark' => (bool) env('APP_LOGO_HAS_WORDMARK', false),
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', ''), '/'),
    'key' => (string) env('APP_KEY', ''),
    'timezone' => (string) env('APP_TIMEZONE', 'UTC'),
    // Default locale is Arabic (spec §2). Map of supported locales => direction.
    'locale' => (string) env('APP_LOCALE', 'ar'),
    'fallback_locale' => (string) env('APP_FALLBACK_LOCALE', 'en'),
    'locales' => ['ar' => 'rtl', 'en' => 'ltr'],
    // Staging must not be indexed (spec §14.5).
    // Absolute path of the web document root (differs in a public_html split layout).
    'public_path' => rtrim((string) (env('PUBLIC_PATH') ?: BASE_PATH . '/public'), '/'),
    'indexable' => env('APP_ENV', 'production') === 'production',
];
