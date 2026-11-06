<?php

declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Controllers\Api\ProgressController;
use App\Controllers\Api\ReaderApiController;
use App\Controllers\DemoPageImageController;
use App\Middleware\AuthMiddleware;

// Protected reader (Phase 4). Every image request re-checks: signed short-lived token bound
// to the PHP session, reading session validity, book visibility + members policy, file version
// and page range — then serves a freshly watermarked WebP. No route ever returns a PDF.
$router->post('/api/reader/sessions', [ReaderApiController::class, 'start'])->name('api.reader.start');
$router->post('/api/reader/sessions/renew', [ReaderApiController::class, 'renew'])->name('api.reader.renew');
$router->get('/api/books/{id:\d+}/reader-manifest', [ReaderApiController::class, 'manifest'])->name('api.reader.manifest');
$router->get('/api/books/{id:\d+}/pages/{page:\d{1,5}}', [ReaderApiController::class, 'page'])->name('api.reader.page');
$router->get('/api/books/{id:\d+}/thumbnails/{page:\d{1,5}}', [ReaderApiController::class, 'thumbnail'])->name('api.reader.thumbnail');

// Member reading progress (MySQL), spec §7.3.
$router->group(['middleware' => [AuthMiddleware::class]], function ($r): void {
    $r->post('/api/reading/progress', [ProgressController::class, 'store'])->name('api.progress.store');
    $r->get('/api/reading/progress/{bookId:\d+}', [ProgressController::class, 'show'])->name('api.progress.show');
});

// Real API routes (spec §12.2) arrive in Phases 3–5.
// There is intentionally NO route that returns an original PDF file.

// DEMO ONLY: synthetic page images (generated with GD, watermark baked in) so
// the reader UI can be reviewed. Not registered unless demo mode is on, which
// is impossible in production. Phase 4 replaces these with token-protected
// /api/books/{id}/pages/{n} served from private storage.
if (config('ui.demo', false)) {
    $router->get('/api/demo/books/{slug:[a-z0-9-]+}/pages/{page:\d{1,4}}', [DemoPageImageController::class, 'page'])->name('demo.page');
    $router->get('/api/demo/books/{slug:[a-z0-9-]+}/thumbnails/{page:\d{1,4}}', [DemoPageImageController::class, 'thumbnail'])->name('demo.thumbnail');
}
