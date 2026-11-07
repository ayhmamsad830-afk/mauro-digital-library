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
 * In-process HTTP tests through the real Kernel (router, CSRF, auth/admin
 * middleware, controllers, views) with the database repository on the SQLite
 * harness. Covers authorization, CSRF, admin CRUD and public visibility.
 */
final class AdminHttpTest extends TestCase
{
    private array $ids = [];
    private mixed $demo;

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        $_SESSION = [];
    }

    public function tearDown(): void
    {
        Config::set('ui.demo', $this->demo);
        Kernel::resetRouter();
        $_SESSION = [];
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = [], ?int $userId = null, bool $withToken = true, string $locale = 'en'): Response
    {
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
        if ($method !== 'GET' && $withToken) {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method === 'GET' ? 'GET' : 'POST', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
        if (!in_array($method, ['GET', 'POST'], true)) {
            $post['_method'] = $method;
        }
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => $locale]));
    }

    /** Co-authored book: stored order survives the admin form, every display and search. */
    public function testCoAuthorsKeepTheirOrderEverywhere(): void
    {
        $admin = $this->ids['u_admin'];
        $salma = $this->ids['a_salma'];   // "سلمى الحداد" — sorts AFTER "Nora Castell" alphabetically
        $nora = $this->ids['a_nora'];

        // Admin create with the second-alphabetical author first.
        $this->send('POST', '/admin/books', [
            'title' => 'كتاب مشترك', 'slug' => 'co-authored', 'language' => 'ar', 'visibility' => 'public',
            'author_ids' => [(string) $salma, (string) $nora], 'category_ids' => [(string) $this->ids['c_lit']],
        ], $admin);
        $id = (int) Database::scalar("SELECT id FROM books WHERE slug = 'co-authored'");
        $order = static fn () => array_map('intval', Database::query('SELECT author_id FROM book_authors WHERE book_id = :id ORDER BY author_order', ['id' => $id])->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame([$salma, $nora], $order(), 'both authors stored, in submitted order');

        // The edit form lists the selected authors first, in saved order, so re-saving keeps it.
        $form = $this->send('GET', "/admin/books/$id", [], $admin)->body();
        $posS = strpos($form, 'name="author_ids[]" value="' . $salma . '" checked');
        $posN = strpos($form, 'name="author_ids[]" value="' . $nora . '" checked');
        $this->assertTrue($posS !== false && $posN !== false, 'both authors checked in the form');
        $this->assertTrue($posS < $posN, 'first author rendered first even though it sorts later by name');
        $this->send('PUT', "/admin/books/$id", [
            'title' => 'كتاب مشترك', 'slug' => 'co-authored', 'language' => 'ar', 'visibility' => 'public',
            'author_ids' => [(string) $salma, (string) $nora], 'category_ids' => [(string) $this->ids['c_lit']],
        ], $admin);
        $this->assertSame([$salma, $nora], $order(), 'editing never drops or reorders an author');
        $this->assertSame(2, (int) Database::scalar('SELECT COUNT(*) FROM book_authors WHERE book_id = :id', ['id' => $id]));

        // Public pages: give an existing published book a second author (order 2).
        $b1 = $this->ids['b1'];
        Database::query('INSERT INTO book_authors (book_id, author_id, author_order) VALUES (:b, :a, 2)', ['b' => $b1, 'a' => $nora]);
        \App\Services\SearchIndexer::reindexBook($b1);
        $pair = static fn (string $sep) => '#سلمى الحداد(</a>)?</bdi>' . preg_quote($sep, '#') . '<bdi>(<a [^>]+>)?Nora Castell#u';

        $ar = $this->send('GET', '/books/gardens-river', [], null, true, 'ar')->body();
        $this->assertTrue(preg_match($pair('، '), $ar) === 1, 'Arabic detail: «أ، ب»');
        $en = $this->send('GET', '/books/gardens-river')->body();
        $this->assertTrue(preg_match($pair(' and '), $en) === 1, 'English detail: "A and B"');
        foreach (['/', '/books', '/categories/literature'] as $path) {
            $this->assertTrue(preg_match($pair(' and '), $this->send('GET', $path)->body()) === 1, "card on $path");
            $this->assertTrue(preg_match($pair('، '), $this->send('GET', $path, [], null, true, 'ar')->body()) === 1, "Arabic card on $path");
        }
        foreach (['Nora Castell', 'سلمى الحداد', 'Castell'] as $q) {
            $this->assertContains('حدائق النهر الهادئ', $this->send('GET', '/books?q=' . rawurlencode($q))->body(), "search by author \"$q\"");
        }
        $this->assertContains('حدائق النهر الهادئ', $this->send('GET', '/books?author=nora-castell')->body(), 'author filter');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $r = $this->send('GET', '/admin/books');
        $this->assertSame(302, $r->status());
        $this->assertSame('/admin/login?redirect=%2Fadmin%2Fbooks', $r->getHeader('Location'));
    }

    public function testMemberIsForbidden(): void
    {
        $this->assertSame(403, $this->send('GET', '/admin', [], $this->ids['u_member'])->status());
        $this->assertSame(403, $this->send('POST', '/admin/categories', ['name_ar' => 'x'], $this->ids['u_member'])->status());
        $this->assertSame(5, (int) Database::scalar('SELECT COUNT(*) FROM categories'), 'nothing written');
    }

    public function testSuspendedAdminLosesAccessImmediately(): void
    {
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $this->ids['u_admin']]);
        $r = $this->send('GET', '/admin', [], $this->ids['u_admin']);
        $this->assertSame(302, $r->status());
        $this->assertFalse(isset($_SESSION['user_id']), 'session invalidated');
    }

    public function testAdminDashboardShowsRealCounts(): void
    {
        $r = $this->send('GET', '/admin', [], $this->ids['u_admin']);
        $this->assertSame(200, $r->status());
        $this->assertContains('Published books', $r->body());
        $this->assertContains('noindex', (string) $r->getHeader('X-Robots-Tag'));
        $this->assertNotContains('Demo figure', $r->body());
    }

    public function testAdminPostWithoutCsrfIsRejected(): void
    {
        $r = $this->send('POST', '/admin/authors', ['name' => 'No Token'], $this->ids['u_admin'], false);
        $this->assertSame(419, $r->status());
        $this->assertSame(0, (int) Database::scalar("SELECT COUNT(*) FROM authors WHERE name = 'No Token'"));
    }

    public function testCreateEditAndListBookThroughHttp(): void
    {
        $admin = $this->ids['u_admin'];
        $r = $this->send('POST', '/admin/books', [
            'title' => '<script>alert(1)</script> كتاب', 'language' => 'ar', 'visibility' => 'public',
            'author_ids' => [(string) $this->ids['a_nora']], 'category_ids' => [(string) $this->ids['c_lit']],
        ], $admin);
        $this->assertSame(303, $r->status());
        $id = (int) Database::scalar('SELECT MAX(id) FROM books');
        $this->assertSame("/admin/books/$id", $r->getHeader('Location'));

        $edit = $this->send('GET', "/admin/books/$id", [], $admin);
        $this->assertSame(200, $edit->status());
        $this->assertContains('Book created as a draft.', $edit->body(), 'flash message survives the redirect');
        $this->assertNotContains('<script>alert(1)</script>', $edit->body());
        $this->assertContains('&lt;script&gt;', $edit->body());
        $this->assertContains('No PDF file attached', $edit->body(), 'publish blockers listed');

        $upd = $this->send('PUT', "/admin/books/$id", [
            'title' => 'Renamed via PUT', 'slug' => 'renamed-via-put', 'language' => 'en', 'visibility' => 'members',
            'author_ids' => [(string) $this->ids['a_nora']], 'category_ids' => [(string) $this->ids['c_lit']],
        ], $admin);
        $this->assertSame(303, $upd->status());
        $this->assertSame('renamed-via-put', Database::scalar('SELECT slug FROM books WHERE id = :id', ['id' => $id]));

        $list = $this->send('GET', '/admin/books?q=renamed', [], $admin);
        $this->assertContains('Renamed via PUT', $list->body());
        $this->assertContains('renamed-via-put', $list->body());
    }

    public function testInvalidFormRedirectsBackWithErrorsAndOldInput(): void
    {
        $r = $this->send('POST', '/admin/authors', ['name' => '', 'slug' => 'BAD SLUG'], $this->ids['u_admin']);
        $this->assertSame('/admin/authors/create', $r->getHeader('Location'));
        $form = $this->send('GET', '/admin/authors/create', [], $this->ids['u_admin']);
        $this->assertContains('This field is required.', $form->body());
        $this->assertContains('value="BAD SLUG"', $form->body());
        $this->assertContains('aria-invalid="true"', $form->body());
    }

    public function testPublishViaHttpRespectsRulesThenGoesPublic(): void
    {
        $admin = $this->ids['u_admin'];
        $id = $this->ids['b5']; // draft with rights record but no file
        $blocked = $this->send('POST', "/admin/books/$id/publish", [], $admin);
        $this->assertSame(303, $blocked->status());
        $this->assertSame('draft', Database::scalar('SELECT status FROM books WHERE id = :id', ['id' => $id]));
        $this->assertSame(404, $this->send('GET', '/books/secret-draft')->status());

        LibraryFixtures::attachReadyFile($id, 20);
        $this->send('POST', "/admin/books/$id/publish", [], $admin);
        $this->assertSame('published', Database::scalar('SELECT status FROM books WHERE id = :id', ['id' => $id]));
        // Make the publication time not later than the repository clock.
        Database::query('UPDATE books SET published_at = :p WHERE id = :id', ['p' => '2026-11-01 00:00:00', 'id' => $id]);
        $public = $this->send('GET', '/books/secret-draft');
        $this->assertSame(200, $public->status());
        $this->assertContains('مسودة سرية', $public->body());
    }

    public function testCategoryToggleViaHttp(): void
    {
        $id = $this->ids['c_sci'];
        $this->send('POST', "/admin/categories/$id/toggle", [], $this->ids['u_admin']);
        $this->assertSame('inactive', Database::scalar('SELECT status FROM categories WHERE id = :id', ['id' => $id]));
        $this->assertSame(404, $this->send('GET', '/categories/science')->status());
    }

    public function testPublicPagesReadFromTheDatabase(): void
    {
        $home = $this->send('GET', '/');
        $this->assertSame(200, $home->status());
        $this->assertContains('حدائق النهر الهادئ', $home->body());
        $this->assertNotContains('demo-banner', $home->body());
        $this->assertNotContains('مسودة سرية', $home->body());

        $search = $this->send('GET', '/search?q=' . rawurlencode('النُّجوم'));
        $this->assertContains('النُّجُومُ للمبتدئين', $search->body());
        $this->assertContains('Showing 1–1 of 1', $search->body());

        $cat = $this->send('GET', '/categories/science');
        $this->assertContains('100% Pure_Data', $cat->body());
        $this->assertContains('النُّجُومُ للمبتدئين', $cat->body(), 'child category books included');

        $this->assertSame(404, $this->send('GET', '/books/alexandria-school')->status(), 'members-only hidden from guests');
        $this->assertSame(404, $this->send('GET', '/books/archived-book')->status());
        $this->assertSame(404, $this->send('GET', '/books/scheduled-book')->status());
        $this->assertSame(404, $this->send('GET', '/books/deleted-book')->status());

        $authors = $this->send('GET', '/authors');
        $this->assertContains('Nora Castell', $authors->body());
        $this->assertNotContains('كاتب الظل', $authors->body());
    }

    public function testMembersOnlyBooksOverHttpForGuestsAndMembers(): void
    {
        $member = $this->ids['u_member'];
        $q = '/search?q=' . rawurlencode('الإسكندرية');

        // Guest: every public surface hides the book and its existence.
        $_SESSION = [];
        foreach (['/', '/books', '/latest', '/popular', '/categories/history', '/books?author=salma-al-haddad', $q, '/books/gardens-river'] as $path) {
            $body = $this->send('GET', $path)->body();
            $this->assertNotContains('مدرسة الإسكندرية', $body, "guest must not see the title on $path");
            $this->assertNotContains('alexandria-school', $body, "guest must not see the slug on $path");
        }
        $this->assertSame(404, $this->send('GET', '/books/alexandria-school')->status());
        $this->assertContains('Books: 1', $this->send('GET', '/categories')->body(), 'history count excludes it');

        // Member: visible with the members badge.
        $page = $this->send('GET', '/books/alexandria-school', [], $member);
        $this->assertSame(200, $page->status());
        $this->assertContains('مدرسة الإسكندرية', $page->body());
        $this->assertContains('badge--accent', $page->body());
        $this->assertContains('مدرسة الإسكندرية', $this->send('GET', $q, [], $member)->body());
        $this->assertContains('alexandria-school', $this->send('GET', '/books/gardens-river', [], $member)->body(), 'related for members');

        // Suspended member is treated as a guest immediately.
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $member]);
        $this->assertSame(404, $this->send('GET', '/books/alexandria-school', [], $member)->status());
    }

    public function testPageImageEndpointEnforcesMembersPolicy(): void
    {
        // The page-image endpoint (demo generator today, private storage in Phase 4)
        // must apply the same policy as the book page.
        Config::set('ui.demo', true);
        Kernel::resetRouter(); // register the page-image routes
        Database::query('UPDATE books SET page_count = 10 WHERE id IN (:a, :b)', ['a' => $this->ids['b4'], 'b' => $this->ids['b1']]);
        $_SESSION = [];
        $this->assertSame(200, $this->send('GET', '/api/demo/books/gardens-river/pages/1')->status(), 'positive control: public book served to guest');
        $this->assertSame(404, $this->send('GET', '/api/demo/books/alexandria-school/pages/1')->status(), 'guest');
        $this->assertSame(404, $this->send('GET', '/api/demo/books/alexandria-school/thumbnails/1')->status(), 'guest thumb');
        $ok = $this->send('GET', '/api/demo/books/alexandria-school/pages/1', [], $this->ids['u_member']);
        $this->assertSame(200, $ok->status(), 'member');
        $this->assertContains('no-store', (string) $ok->getHeader('Cache-Control'));
        $_SESSION = [];
        $this->assertSame(404, $this->send('GET', '/read/alexandria-school')->status(), 'reader shell for guest');
    }

    public function testInjectionThroughHttpQueryString(): void
    {
        $r = $this->send('GET', '/books?q=' . rawurlencode("' OR 1=1 --") . '&sort=' . rawurlencode('title; DROP TABLE books') . '&page=-5&per_page=99999');
        $this->assertSame(200, $r->status());
        $this->assertContains('empty-state', $r->body());
        $this->assertSame(9, (int) Database::scalar('SELECT COUNT(*) FROM books'));
    }
}
