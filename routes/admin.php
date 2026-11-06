<?php

declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Controllers\Admin\AuthorController;
use App\Controllers\Admin\BookController;
use App\Controllers\Admin\BookFileController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\AnalyticsController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\FilesController;
use App\Controllers\Admin\LogController;
use App\Controllers\Admin\MediaUploadController;
use App\Controllers\Admin\ReviewController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;

// Every admin route: signed-in (AuthMiddleware) AND role=admin re-checked from the DB (AdminMiddleware).
// CSRF is enforced globally for POST/PUT/PATCH/DELETE.
$router->group(['prefix' => '/admin', 'middleware' => [AuthMiddleware::class, AdminMiddleware::class]], function ($r): void {
    $r->get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    $r->get('/books', [BookController::class, 'index'])->name('admin.books.index');
    $r->get('/books/create', [BookController::class, 'create'])->name('admin.books.create');
    $r->post('/books', [BookController::class, 'store'])->name('admin.books.store');
    $r->get('/books/{id:\d+}', [BookController::class, 'edit'])->name('admin.books.edit');
    $r->put('/books/{id:\d+}', [BookController::class, 'update'])->name('admin.books.update');
    $r->post('/books/{id:\d+}/publish', [BookController::class, 'publish'])->name('admin.books.publish');
    $r->post('/books/{id:\d+}/unpublish', [BookController::class, 'unpublish'])->name('admin.books.unpublish');
    $r->post('/books/{id:\d+}/archive', [BookController::class, 'archive'])->name('admin.books.archive');
    $r->post('/books/{id:\d+}/restore', [BookController::class, 'restore'])->name('admin.books.restore');
    // Phase 4: PDF upload/replacement (new version, activated only after full rendering).
    $r->post('/books/{id:\d+}/replace-pdf', [BookFileController::class, 'upload'])->name('admin.books.upload');
    $r->post('/books/{id:\d+}/files/{fileId:\d+}/process', [BookFileController::class, 'process'])->name('admin.books.files.process');
    $r->post('/books/{id:\d+}/files/{fileId:\d+}/retry', [BookFileController::class, 'retry'])->name('admin.books.files.retry');

    $r->get('/categories', [CategoryController::class, 'index'])->name('admin.categories.index');
    $r->get('/categories/create', [CategoryController::class, 'create'])->name('admin.categories.create');
    $r->post('/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
    $r->get('/categories/{id:\d+}', [CategoryController::class, 'edit'])->name('admin.categories.edit');
    $r->put('/categories/{id:\d+}', [CategoryController::class, 'update'])->name('admin.categories.update');
    $r->post('/categories/{id:\d+}/toggle', [CategoryController::class, 'toggle'])->name('admin.categories.toggle');

    $r->get('/authors', [AuthorController::class, 'index'])->name('admin.authors.index');
    $r->get('/authors/create', [AuthorController::class, 'create'])->name('admin.authors.create');
    $r->post('/authors', [AuthorController::class, 'store'])->name('admin.authors.store');
    $r->get('/authors/{id:\d+}', [AuthorController::class, 'edit'])->name('admin.authors.edit');
    $r->put('/authors/{id:\d+}', [AuthorController::class, 'update'])->name('admin.authors.update');

    // Phase 5: covers / author photos (re-encoded to WebP, private storage).
    $r->post('/books/{id:\d+}/cover', [MediaUploadController::class, 'cover'])->name('admin.books.cover');
    $r->post('/books/{id:\d+}/cover/delete', [MediaUploadController::class, 'removeCover'])->name('admin.books.cover.delete');
    $r->post('/authors/{id:\d+}/photo', [MediaUploadController::class, 'authorPhoto'])->name('admin.authors.photo');
    $r->get('/media/covers/{id:\d+}', [MediaUploadController::class, 'showCover'])->name('admin.media.cover');
    $r->get('/media/authors/{id:\d+}', [MediaUploadController::class, 'showAuthorPhoto'])->name('admin.media.author');

    $r->get('/files', [FilesController::class, 'index'])->name('admin.files.index');
    $r->get('/users', [UserController::class, 'index'])->name('admin.users.index');
    $r->post('/users/{id:\d+}/status', [UserController::class, 'status'])->name('admin.users.status');
    $r->post('/users/{id:\d+}/role', [UserController::class, 'role'])->name('admin.users.role');
    $r->get('/reviews', [ReviewController::class, 'index'])->name('admin.reviews.index');
    $r->post('/reviews/{id:\d+}/moderate', [ReviewController::class, 'moderate'])->name('admin.reviews.moderate');
    $r->get('/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');
    $r->get('/logs', [LogController::class, 'index'])->name('admin.logs.index');
    $r->get('/settings', [SettingsController::class, 'edit'])->name('admin.settings');
    $r->post('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
});

// Design preview with fictional figures — demo mode only (never production).
if (config('ui.demo', false)) {
    $router->get('/demo/admin', [DashboardController::class, 'preview'])->name('admin.preview');
}
