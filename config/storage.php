<?php

declare(strict_types=1);

/*
 * All paths below MUST be outside the web document root (public/).
 * In a split hosting layout (public_html + private sibling folder), set
 * STORAGE_PATH to the absolute private path.
 */
$root = rtrim((string) (env('STORAGE_PATH') ?: BASE_PATH . '/storage'), '/');

return [
    'root' => $root,
    'logs' => $root . '/logs',
    'backups' => $root . '/backups',
    'cache' => $root . '/cache',
    'original_pdf' => $root . '/private/original-pdf',
    'rendered_pages' => $root . '/private/rendered-pages',
    'thumbnails' => $root . '/private/thumbnails',
    'watermark_cache' => $root . '/private/watermark-cache',
    'covers' => $root . '/private/covers',
    'temp' => $root . '/private/temp',
    'pdf_max_bytes' => (int) env('PDF_MAX_UPLOAD_MB', 10) * 1024 * 1024,
    'cover_max_bytes' => (int) env('COVER_MAX_UPLOAD_MB', 2) * 1024 * 1024,
];
