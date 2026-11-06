<?php

declare(strict_types=1);

return [
    'engine' => (string) env('PDF_RENDER_ENGINE', 'poppler'),
    'binaries' => [
        'pdftoppm' => (string) env('PDFTOPPM_BINARY', 'pdftoppm'),
        'pdfinfo' => (string) env('PDFINFO_BINARY', 'pdfinfo'),
        'mutool' => (string) env('MUTOOL_BINARY', 'mutool'),
    ],
    'dpi' => (int) env('PDF_RENDER_DPI', 150),
    'format' => (string) env('PDF_RENDER_FORMAT', 'webp'),
    'quality' => (int) env('PDF_RENDER_QUALITY', 82),
    'thumbnail_width' => (int) env('PDF_THUMBNAIL_WIDTH', 220),
    'timeout' => (int) env('PDF_RENDER_TIMEOUT', 120),
    'reader_token_ttl' => (int) env('READER_TOKEN_TTL', 300),

    // Phase 4 — pipeline limits
    'page_width' => (int) env('PDF_PAGE_WIDTH', 1400),          // master page width in px (height keeps aspect)
    'max_pages' => (int) env('PDF_MAX_PAGES', 2000),            // reject larger documents
    'batch_pages' => (int) env('PDF_RENDER_BATCH', 10),         // pages per pdftoppm call
    'max_attempts' => (int) env('PDF_RENDER_MAX_ATTEMPTS', 3),  // automatic retries before "failed"
    'stale_after' => (int) env('PDF_RENDER_STALE_AFTER', 1800), // seconds before a stuck job is reclaimed
    'min_free_disk_mb' => (int) env('PDF_MIN_FREE_DISK_MB', 500),
    'sync_max_pages' => (int) env('PDF_SYNC_MAX_PAGES', 60),    // "process now" button limit (else cron worker)

    // Reader sessions (spec §7.5, §13.4)
    'reader_session_ttl' => (int) env('READER_SESSION_TTL', 7200), // max lifetime of one reading session
    'watermark_opacity' => (int) env('WATERMARK_ALPHA', 98),        // GD alpha 0 (opaque) … 127 (transparent)
];
