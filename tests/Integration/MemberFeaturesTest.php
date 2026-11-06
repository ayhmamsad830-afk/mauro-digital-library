<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Member area through the real Kernel: favourites, bookmarks, reviews,
 * history, guest-progress import and account pages. Covers IDOR, XSS
 * escaping, CSRF, members-only visibility and suspended accounts.
 */
final class MemberFeaturesTest extends TestCase
{
    private array $ids = [];
    private array $saved = [];

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        foreach (['ui.demo', 'security.rate_limits.reviews'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('ui.demo', false);
        Kernel::resetRouter();
        LibraryRepositoryFactory::set(new MySqlLibraryRepository());
        Database::query('UPDATE books SET page_count = 12');
        $_SESSION = [];
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        LibraryRepositoryFactory::set(null);
        Kernel::resetRouter();
        $_SESSION = [];
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $body = [], ?int $user = null, bool $json = true, bool $csrf = true): Response
    {
        unset($_SESSION['user_id']);
        if ($user !== null) {
            $_SESSION['user_id'] = $user;
        }
        Session::resetForNextRequest();
        $srv = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
        if ($json) {
            $srv['HTTP_ACCEPT'] = 'application/json';
        }
        $raw = null;
        $post = [];
        if ($method !== 'GET') {
            if ($csrf) {
                $srv['HTTP_X_CSRF_TOKEN'] = Csrf::token();
            }
            if ($json) {
                $srv['CONTENT_TYPE'] = 'application/json';
                $raw = json_encode($body);
            } else {
                $post = $body + ($csrf ? ['_token' => Csrf::token()] : []);
            }
        }
        return (new Kernel())->handle(new Request([], $post, $srv, ['dl_locale' => 'en'], [], $raw));
    }

    private function m(): int
    {
        return $this->ids['u_member'];
    }

    public function testAccountPagesRequireAnActiveAccount(): void
    {
        foreach (['/account', '/account/history', '/account/favorites', '/account/bookmarks'] as $p) {
            $r = $this->send('GET', $p, [], null, false);
            $this->assertSame(302, $r->status(), $p);
            $this->assertContains('/login?redirect=' . rawurlencode($p), (string) $r->getHeader('Location'));
            $this->assertSame(200, $this->send('GET', $p, [], $this->m(), false)->status(), "$p member");
            $this->assertSame(302, $this->send('GET', $p, [], $this->ids['u_suspended'], false)->status(), "$p suspended");
        }
        $this->assertSame(401, $this->send('POST', '/account/favorites/' . $this->ids['b1'])->status(), 'guest write → 401');
        $this->assertSame(419, $this->send('POST', '/account/favorites/' . $this->ids['b1'], [], $this->m(), true, false)->status(), 'CSRF');
    }

    public function testFavoritesToggleAndRespectVisibility(): void
    {
        $r = $this->send('POST', '/account/favorites/' . $this->ids['b1'], [], $this->m());
        $this->assertSame(['favorite' => true], array_intersect_key(json_decode($r->body(), true), ['favorite' => 1]));
        $this->send('POST', '/account/favorites/' . $this->ids['b4'], [], $this->m());
        $page = $this->send('GET', '/account/favorites', [], $this->m(), false)->body();
        $this->assertContains('gardens-river', $page);
        $this->assertContains('alexandria-school', $page, 'members-only book visible to a member');
        $r = $this->send('POST', '/account/favorites/' . $this->ids['b1'], [], $this->m());
        $this->assertFalse(json_decode($r->body(), true)['favorite']);
        foreach (['b5', 'b6', 'b7', 'b8'] as $hidden) {
            $this->assertSame(404, $this->send('POST', '/account/favorites/' . $this->ids[$hidden], [], $this->m())->status(), $hidden);
        }
        $this->assertSame(404, $this->send('POST', '/account/favorites/999999', [], $this->m())->status());
        // A favourite whose book later becomes unpublished disappears from the list.
        Database::query("UPDATE books SET status = 'archived' WHERE id = :id", ['id' => $this->ids['b4']]);
        $this->assertNotContains('alexandria-school', $this->send('GET', '/account/favorites', [], $this->m(), false)->body());
        // HTML form fallback redirects back with a flash.
        $r = $this->send('POST', '/account/favorites/' . $this->ids['b2'], ['redirect' => '/books/cartographer-notebook'], $this->m(), false);
        $this->assertSame(303, $r->status());
        $this->assertSame('/books/cartographer-notebook', $r->getHeader('Location'));
        $r = $this->send('POST', '/account/favorites/' . $this->ids['b2'], ['redirect' => 'https://evil.example/'], $this->m(), false);
        $this->assertFalse(str_contains((string) $r->getHeader('Location'), 'evil'), 'open redirect refused');
    }

    public function testBookmarksAreOwnerScopedAndValidated(): void
    {
        $id = json_decode($this->send('POST', '/account/bookmarks', ['book_id' => $this->ids['b1'], 'page' => 3, 'title' => '<script>alert(1)</script>'], $this->m())->body(), true)['id'];
        $this->assertTrue($id > 0);
        foreach ([0, 13, -1] as $bad) {
            $this->assertSame(422, $this->send('POST', '/account/bookmarks', ['book_id' => $this->ids['b1'], 'page' => $bad], $this->m())->status(), "page $bad");
        }
        $this->assertSame(404, $this->send('POST', '/account/bookmarks', ['book_id' => $this->ids['b5'], 'page' => 1], $this->m())->status(), 'draft');
        $page = $this->send('GET', '/account/bookmarks', [], $this->m(), false)->body();
        $this->assertNotContains('<script>alert(1)</script>', $page);
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        // IDOR: the admin cannot delete the member's bookmark.
        $this->assertSame(404, $this->send('POST', "/account/bookmarks/$id/delete", [], $this->ids['u_admin'])->status());
        $this->assertSame(1, (int) Database::scalar('SELECT COUNT(*) FROM bookmarks WHERE id = :i', ['i' => $id]));
        $this->assertSame(200, $this->send('POST', "/account/bookmarks/$id/delete", [], $this->m())->status());
        $this->assertSame(0, (int) Database::scalar('SELECT COUNT(*) FROM bookmarks'));
        $this->assertSame(404, $this->send('POST', "/account/bookmarks/$id/delete", [], $this->m())->status(), 'already gone');
    }

    public function testReviewsCreateEditDeleteAndRecomputeRating(): void
    {
        Database::query('DELETE FROM reviews'); // fixtures carry sample reviews
        Database::query('UPDATE books SET rating_avg = 0, rating_count = 0');
        $url = '/books/gardens-river/review';
        $this->assertSame(422, $this->send('POST', $url, ['rating' => 6], $this->m())->status());
        $this->assertSame(422, $this->send('POST', $url, ['rating' => '3; DROP TABLE reviews'], $this->m())->status());
        $this->assertSame(422, $this->send('POST', $url, ['rating' => 4, 'comment' => str_repeat('x', 2001)], $this->m())->status());
        $this->assertSame(200, $this->send('POST', $url, ['rating' => 4, 'comment' => '<img src=x onerror=alert(1)> رائع'], $this->m())->status());
        $this->assertSame(200, $this->send('POST', $url, ['rating' => 2], $this->ids['u_admin'])->status());
        $b = fn () => Database::first('SELECT rating_avg, rating_count FROM books WHERE id = :i', ['i' => $this->ids['b1']]);
        $this->assertSame([3.0, 2], [(float) $b()['rating_avg'], (int) $b()['rating_count']]);

        // Edit keeps one review per user and a moderator's hidden status.
        Database::query("UPDATE reviews SET status = 'hidden' WHERE user_id = :u", ['u' => $this->ids['u_admin']]);
        $this->send('POST', $url, ['rating' => 5], $this->ids['u_admin']);
        $this->assertSame(['hidden', 1], [Database::scalar('SELECT status FROM reviews WHERE user_id = :u', ['u' => $this->ids['u_admin']]),
            (int) Database::scalar('SELECT COUNT(*) FROM reviews WHERE user_id = :u', ['u' => $this->ids['u_admin']])]);
        $this->assertSame([4.0, 1], [(float) $b()['rating_avg'], (int) $b()['rating_count']], 'hidden review excluded');

        $html = $this->send('GET', '/books/gardens-river', [], null, false)->body();
        $this->assertNotContains('<img src=x onerror', $html);
        $this->assertContains('&lt;img src=x onerror=alert(1)&gt;', $html);

        // HTML form with an invalid rating returns to the page with an error, not a crash.
        $r = $this->send('POST', $url, ['rating' => 9], $this->m(), false);
        $this->assertSame(303, $r->status());
        $this->assertContains('#reviews', (string) $r->getHeader('Location'));

        $this->assertSame(200, $this->send('POST', "$url/delete", [], $this->m())->status());
        $this->assertSame([0.0, 0], [(float) $b()['rating_avg'], (int) $b()['rating_count']]);
        $this->assertSame(404, $this->send('POST', "$url/delete", [], $this->m())->status());
        $this->assertSame(404, $this->send('POST', '/books/secret-draft/review', ['rating' => 5], $this->m())->status());
        $this->assertSame(401, $this->send('POST', '/books/gardens-river/review', ['rating' => 5])->status(), 'guest cannot review');
    }

    public function testReviewRateLimit(): void
    {
        Config::set('security.rate_limits.reviews', ['max' => 2, 'window' => 60]);
        $this->send('POST', '/books/gardens-river/review', ['rating' => 3], $this->m());
        $this->send('POST', '/books/gardens-river/review', ['rating' => 4], $this->m());
        $this->assertSame(429, $this->send('POST', '/books/gardens-river/review', ['rating' => 5], $this->m())->status());
    }

    public function testSuspendedReviewersDoNotCount(): void
    {
        $this->send('POST', '/books/gardens-river/review', ['rating' => 5], $this->m());
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :u", ['u' => $this->m()]);
        \App\Services\Member\MemberLibrary::recomputeRating($this->ids['b1']);
        $this->assertSame(0, (int) Database::scalar('SELECT rating_count FROM books WHERE id = :i', ['i' => $this->ids['b1']]));
    }

    public function testHistoryAndGuestProgressImport(): void
    {
        $f1 = LibraryFixtures::attachReadyFile($this->ids['b1'], 12);
        $f2 = LibraryFixtures::attachReadyFile($this->ids['b2'], 8);
        LibraryFixtures::attachReadyFile($this->ids['b5'], 8);
        $v2 = (int) Database::scalar('SELECT version_number FROM book_files WHERE id = :f', ['f' => $f2]);
        // Existing cloud progress on b2 must not be overwritten.
        Database::query("INSERT INTO reading_progress (user_id, book_id, book_file_id, current_page, total_pages, progress_percent, last_read_at, updated_at)
                         VALUES (:u, :b, :f, 7, 8, 87.5, '2026-11-05 10:00:00', '2026-11-05 10:00:00')",
            ['u' => $this->m(), 'b' => $this->ids['b2'], 'f' => $f2]);
        $items = [
            ['slug' => 'gardens-river', 'page' => 4, 'v' => 1],
            ['slug' => 'cartographer-notebook', 'page' => 2, 'v' => $v2],
            ['slug' => 'secret-draft', 'page' => 2, 'v' => 1],
            ['slug' => '../etc/passwd', 'page' => 1, 'v' => 1],
            ['slug' => 'stars-beginners', 'page' => 1, 'v' => 1],
            'garbage',
        ];
        $r = $this->send('POST', '/api/reading/progress/import', ['items' => $items], $this->m());
        $this->assertSame(1, json_decode($r->body(), true)['imported'], $r->body());
        $this->assertSame(7, (int) Database::scalar('SELECT current_page FROM reading_progress WHERE book_id = :b', ['b' => $this->ids['b2']]));
        $this->assertSame(4, (int) Database::scalar('SELECT current_page FROM reading_progress WHERE book_id = :b AND book_file_id = :f', ['b' => $this->ids['b1'], 'f' => $f1]));
        $this->assertSame(401, $this->send('POST', '/api/reading/progress/import', ['items' => $items])->status(), 'guest');

        $hist = $this->send('GET', '/account/history', [], $this->m(), false)->body();
        $this->assertContains('gardens-river', $hist);
        $this->assertContains('cartographer-notebook', $hist);
        $this->assertNotContains('secret-draft', $hist);
        $this->assertNotContains('sub-member', $hist, 'no google sub leaked');
    }
}
