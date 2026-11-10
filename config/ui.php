<?php

declare(strict_types=1);

$env = (string) env('APP_ENV', 'production');

return [
    // Demo mode serves FICTIONAL sample data from app/Demo for design review.
    // It can never be enabled in production.
    'demo' => $env !== 'production' && (bool) env('APP_DEMO_UI', false),

    // Catalogue page sizes allowed by the spec (§6.2); default from settings in Phase 3.
    'per_page_options' => [12, 24, 36],
    'per_page_default' => 24,

    // Home page section sizes.
    'home_latest' => 6,
    'home_popular' => 8,
    'home_categories' => 8,

    // Cosmic cursor trail on public pages (desktop mouse + dark theme only; always
    // off for touch, reduced motion and low-power devices). UI_CURSOR_TRAIL=false disables it.
    'cursor_trail' => (bool) env('UI_CURSOR_TRAIL', true),
];
