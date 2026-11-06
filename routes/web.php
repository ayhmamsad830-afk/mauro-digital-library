<?php

declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\BookController;
use App\Controllers\CatalogController;
use App\Controllers\HomeController;
use App\Controllers\MediaController;
use App\Controllers\MemberController;
use App\Middleware\AuthMiddleware;
use App\Controllers\PageController;
use App\Controllers\PreferenceController;
use App\Controllers\ReaderController;
use App\Controllers\StyleguideController;

$router->get('/', [HomeController::class, 'index'])->name('home');

// Library (spec §5 site map)
$router->get('/books', [CatalogController::class, 'index'])->name('books.index');
$router->get('/books/{slug:[a-z0-9-]+}', [BookController::class, 'show'])->name('books.show');
$router->get('/latest', [CatalogController::class, 'latest'])->name('books.latest');
$router->get('/popular', [CatalogController::class, 'popular'])->name('books.popular');
$router->get('/search', [CatalogController::class, 'search'])->name('search');
$router->get('/categories', [CatalogController::class, 'categories'])->name('categories.index');
$router->get('/categories/{slug:[a-z0-9-]+}', [CatalogController::class, 'category'])->name('categories.show');
$router->get('/authors', [CatalogController::class, 'authors'])->name('authors.index');

// Protected reader shell (page images only — never the PDF)
$router->get('/read/{slug:[a-z0-9-]+}', [ReaderController::class, 'show'])->name('reader.show');

// Accounts (Google Sign-In is wired in Phase 5)
$router->get('/login', [AuthController::class, 'login'])->name('login');
$router->post('/api/auth/google', [AuthController::class, 'google'])->name('api.auth.google');
$router->post('/logout', [AuthController::class, 'logout'])->name('logout');

// Member area (Phase 5) — signed-in, active accounts only; CSRF on every write.
$router->group(['middleware' => [AuthMiddleware::class]], function ($r): void {
    $r->get('/account', [AccountController::class, 'index'])->name('account');
    $r->get('/account/history', [AccountController::class, 'history'])->name('account.history');
    $r->get('/account/favorites', [AccountController::class, 'favorites'])->name('account.favorites');
    $r->get('/account/bookmarks', [AccountController::class, 'bookmarks'])->name('account.bookmarks');
    $r->post('/account/favorites/{bookId:\d+}', [MemberController::class, 'toggleFavorite'])->name('member.favorite');
    $r->post('/account/bookmarks', [MemberController::class, 'addBookmark'])->name('member.bookmark.add');
    $r->post('/account/bookmarks/{id:\d+}/delete', [MemberController::class, 'deleteBookmark'])->name('member.bookmark.delete');
    $r->post('/books/{slug:[a-z0-9-]+}/review', [MemberController::class, 'saveReview'])->name('member.review.save');
    $r->post('/books/{slug:[a-z0-9-]+}/review/delete', [MemberController::class, 'deleteReview'])->name('member.review.delete');
    $r->post('/api/reading/progress/import', [MemberController::class, 'importProgress'])->name('api.progress.import');
});

// Static pages
$router->get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');
$router->get('/terms', [PageController::class, 'terms'])->name('pages.terms');
$router->get('/copyright', [PageController::class, 'copyright'])->name('pages.copyright');

// Preferences
$router->post('/locale', [PreferenceController::class, 'setLocale'])->name('locale.update');

$router->get('/robots.txt', [HomeController::class, 'robots'])->name('robots');
$router->get('/sitemap.xml', [HomeController::class, 'sitemap'])->name('sitemap');
// Covers / author photos from private storage; visibility re-checked per request.
$router->get('/media/covers/{id:\d+}', [MediaController::class, 'cover'])->name('media.cover');
$router->get('/media/authors/{id:\d+}', [MediaController::class, 'authorPhoto'])->name('media.author');

// Design-system reference — demo/non-production only.
if (config('ui.demo', false)) {
    $router->get('/styleguide', [StyleguideController::class, 'index'])->name('styleguide');
    $router->get('/demo/account', [AccountController::class, 'preview'])->name('account.preview');
}
