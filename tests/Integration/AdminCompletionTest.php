<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\UserAdminService;
use App\Services\Media\ImageUploadService;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\Support\TestStorage;
use Tests\TestCase;

/**
 * Admin screens added in Phase 5: users, review moderation, analytics,
 * activity log, settings, files overview, covers and author photos.
 */
final class AdminCompletionTest extends TestCase
{
    private array $ids = [];
    private mixed $demo;
    private string $root = '';

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->demo = Config::get('ui.demo');
        Config::set('ui.demo', false);
        Kernel::resetRouter();
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
        $this->root = TestStorage::create();
        $_SESSION = [];
    }

    public function tearDown(): void
    {
        Config::set('ui.demo', $this->demo);
        LibraryRepositoryFactory::set(null);
        Kernel::resetRouter();
        TestStorage::destroy($this->root);
        $_SESSION = [];
        TestDatabase::release();
    }

    private function send(string $method, string $uri, array $post = [], ?int $userId = null): Response
    {
        unset($_SESSION['user_id']);
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
        if ($method !== 'GET') {
            $post['_token'] = Csrf::token();
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
        return (new Kernel())->handle(new Request($query, $post, $server, ['dl_locale' => 'en']));
    }

    private function admin(): int
    {
        return $this->ids['u_admin'];
    }

    private function png(int $w = 300, int $h = 450, string $append = ''): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 70));
        $path = $this->root . '/private/temp/' . bin2hex(random_bytes(4)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);
        if ($append !== '') {
            file_put_contents($path, $append, FILE_APPEND);
        }
        return $path;
    }

    public function testEveryNewScreenIsAdminOnlyAndRendersWithoutInlineStyles(): void
    {
        $pages = ['/admin/users', '/admin/users?q=member&role=user&status=active', '/admin/reviews', '/admin/reviews?status=hidden',
            '/admin/analytics', '/admin/analytics?days=7', '/admin/logs', '/admin/settings', '/admin/files', '/admin/files?status=failed'];
        foreach ($pages as $p) {
            $this->assertSame(302, $this->send('GET', $p)->status(), "guest $p");
            $this->assertSame(403, $this->send('GET', $p, [], $this->ids['u_member'])->status(), "member $p");
            $r = $this->send('GET', $p, [], $this->admin());
            $this->assertSame(200, $r->status(), "admin $p");
            $this->assertFalse((bool) preg_match('/\sstyle\s*=|<style|<script(?![^>]*\ssrc=)/i', $r->body()), "no inline style/script on $p");
            $this->assertNotContains('is-disabled', $r->body(), 'all admin nav items are live');
        }
        foreach (['/admin/users/' . $this->ids['u_member'] . '/status', '/admin/reviews/1/moderate', '/admin/settings', '/admin/books/' . $this->ids['b1'] . '/cover'] as $p) {
            $this->assertSame(403, $this->send('POST', $p, ['status' => 'suspended'], $this->ids['u_member'])->status(), "member POST $p");
        }
        $this->assertSame('active', Database::scalar('SELECT status FROM users WHERE id = :i', ['i' => $this->ids['u_member']]));
    }

    public function testSuspendingAMemberTakesEffectImmediatelyAndIsLogged(): void
    {
        Database::query('DELETE FROM reviews');
        Database::query("INSERT INTO reviews (user_id, book_id, rating, status) VALUES (:u, :b, 5, 'published'), (:a, :b, 3, 'published')",
            ['u' => $this->ids['u_member'], 'a' => $this->admin(), 'b' => $this->ids['b1']]);
        \App\Services\Member\MemberLibrary::recomputeRating($this->ids['b1']);
        $this->assertSame(200, $this->send('GET', '/account', [], $this->ids['u_member'])->status());

        $r = $this->send('POST', '/admin/users/' . $this->ids['u_member'] . '/status', ['status' => 'suspended', 'redirect' => '/admin/users?status=active'], $this->admin());
        $this->assertSame(303, $r->status());
        $this->assertSame('/admin/users?status=active', $r->getHeader('Location'));
        $this->assertSame('suspended', Database::scalar('SELECT status FROM users WHERE id = :i', ['i' => $this->ids['u_member']]));
        $this->assertSame(302, $this->send('GET', '/account', [], $this->ids['u_member'])->status(), 'suspended account signed out on next request');
        $this->assertSame([3.0, 1], [(float) Database::scalar('SELECT rating_avg FROM books WHERE id = :i', ['i' => $this->ids['b1']]),
            (int) Database::scalar('SELECT rating_count FROM books WHERE id = :i', ['i' => $this->ids['b1']])], 'suspended reviewer no longer counted');
        $this->assertSame(1, (int) Database::scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'user.suspended' AND entity_id = :i", ['i' => $this->ids['u_member']]));

        $this->send('POST', '/admin/users/' . $this->ids['u_member'] . '/status', ['status' => 'active'], $this->admin());
        $this->assertSame(200, $this->send('GET', '/account', [], $this->ids['u_member'])->status());
        $this->assertSame(2, (int) Database::scalar('SELECT rating_count FROM books WHERE id = :i', ['i' => $this->ids['b1']]));
    }

    public function testRoleChangesAndGuardRails(): void
    {
        $self = $this->send('POST', '/admin/users/' . $this->admin() . '/status', ['status' => 'suspended'], $this->admin());
        $this->assertSame(303, $self->status());
        $this->assertSame('active', Database::scalar('SELECT status FROM users WHERE id = :i', ['i' => $this->admin()]), 'cannot suspend self');
        $this->send('POST', '/admin/users/' . $this->admin() . '/role', ['role' => 'user'], $this->admin());
        $this->assertSame('admin', Database::scalar('SELECT role FROM users WHERE id = :i', ['i' => $this->admin()]), 'cannot demote self');
        $this->assertSame(422, $this->send('POST', '/admin/users/' . $this->ids['u_member'] . '/role', ['role' => 'owner'], $this->admin())->status() === 303 ? 422 : 0);
        $this->assertSame('user', Database::scalar('SELECT role FROM users WHERE id = :i', ['i' => $this->ids['u_member']]), 'unknown role refused');
        $this->assertSame(404, $this->send('POST', '/admin/users/999999/status', ['status' => 'suspended'], $this->admin())->status());

        $this->send('POST', '/admin/users/' . $this->ids['u_member'] . '/role', ['role' => 'admin'], $this->admin());
        $this->assertSame('admin', Database::scalar('SELECT role FROM users WHERE id = :i', ['i' => $this->ids['u_member']]));
        $this->assertSame(200, $this->send('GET', '/admin', [], $this->ids['u_member'])->status(), 'new admin has access');
        $this->send('POST', '/admin/users/' . $this->ids['u_member'] . '/role', ['role' => 'user'], $this->admin());
        $this->assertSame(403, $this->send('GET', '/admin', [], $this->ids['u_member'])->status(), 'demotion is immediate');

        // The last active admin is protected even from another admin's request.
        try {
            UserAdminService::setStatus($this->admin(), 'suspended', 999999, null);
            $this->assertTrue(false, 'last admin suspended');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertSame(['user.promoted', 'user.demoted'], array_column(Database::all("SELECT action FROM admin_activity_logs WHERE entity_type = 'user' ORDER BY id"), 'action'));
    }

    public function testUserListSearchAndEscaping(): void
    {
        Database::query("UPDATE users SET name = '<script>alert(1)</script>' WHERE id = :i", ['i' => $this->ids['u_member']]);
        $html = $this->send('GET', '/admin/users?q=MEMBER@', [], $this->admin())->body();
        $this->assertContains('member@example.invalid', $html);
        $this->assertNotContains('admin@example.invalid', $html);
        $this->assertNotContains('<script>alert(1)</script>', $html);
        $this->assertContains('&lt;script&gt;', $html);
        $this->assertContains('suspended@example.invalid', $this->send('GET', '/admin/users?status=suspended', [], $this->admin())->body());
        $this->assertNotContains('member@', $this->send('GET', '/admin/users?q=%25', [], $this->admin())->body(), 'LIKE wildcard is literal');
    }

    public function testReviewModerationHidesPubliclyAndRecomputes(): void
    {
        Database::query('DELETE FROM reviews');
        Database::query("INSERT INTO reviews (user_id, book_id, rating, comment, status) VALUES (:u, :b, 1, 'Spam spam <b>bold</b>', 'published'), (:a, :b, 5, 'Lovely book', 'published')",
            ['u' => $this->ids['u_member'], 'a' => $this->admin(), 'b' => $this->ids['b1']]);
        \App\Services\Member\MemberLibrary::recomputeRating($this->ids['b1']);
        $id = (int) Database::scalar('SELECT id FROM reviews WHERE user_id = :u', ['u' => $this->ids['u_member']]);
        $list = $this->send('GET', '/admin/reviews', [], $this->admin())->body();
        $this->assertContains('Spam spam &lt;b&gt;bold&lt;/b&gt;', $list);
        $this->assertContains('Spam spam', $this->send('GET', '/books/gardens-river')->body());

        $this->assertSame(303, $this->send('POST', "/admin/reviews/$id/moderate", ['status' => 'hidden'], $this->admin())->status());
        $this->assertNotContains('Spam spam', $this->send('GET', '/books/gardens-river')->body(), 'hidden from the public page');
        $this->assertSame(5.0, (float) Database::scalar('SELECT rating_avg FROM books WHERE id = :i', ['i' => $this->ids['b1']]));
        $this->assertSame((string) $this->admin(), (string) Database::scalar('SELECT moderated_by FROM reviews WHERE id = :i', ['i' => $id]));
        $this->assertContains('Spam spam', $this->send('GET', '/admin/reviews?status=hidden', [], $this->admin())->body());

        $this->send('POST', "/admin/reviews/$id/moderate", ['status' => 'published'], $this->admin());
        $this->assertSame(3.0, (float) Database::scalar('SELECT rating_avg FROM books WHERE id = :i', ['i' => $this->ids['b1']]));
        $this->assertSame(422, $this->send('POST', "/admin/reviews/$id/moderate", ['status' => 'deleted'], $this->admin())->status());
        $this->assertSame(404, $this->send('POST', '/admin/reviews/999999/moderate', ['status' => 'hidden'], $this->admin())->status());
        $this->assertSame(['review.hidden', 'review.published'], array_column(Database::all("SELECT action FROM admin_activity_logs WHERE entity_type = 'review' ORDER BY id"), 'action'));
    }

    public function testAnalyticsCountsRealSessionsOnly(): void
    {
        $now = strtotime('2026-11-05 12:00:00 UTC');
        $empty = AnalyticsService::report(7, $now);
        $this->assertSame(0, $empty['totals']['sessions']);
        $this->assertSame(7, count($empty['days']));
        $ins = function (?int $user, int $book, string $at, string $device, int $pages): void {
            Database::query('INSERT INTO reading_sessions (user_id, book_id, session_token_hash, reader_pseudonym, device_type, pages_viewed, started_at, expires_at)
                             VALUES (:u, :b, :h, :p, :d, :n, :s, :e)', ['u' => $user, 'b' => $book, 'h' => bin2hex(random_bytes(16)), 'p' => 'R-TEST', 'd' => $device, 'n' => $pages, 's' => $at, 'e' => $at]);
        };
        $ins($this->ids['u_member'], $this->ids['b1'], '2026-11-05 09:00:00', 'mobile', 10);
        $ins(null, $this->ids['b1'], '2026-11-05 10:00:00', 'desktop', 4);
        $ins(null, $this->ids['b2'], '2026-11-03 10:00:00', 'mobile', 2);
        $ins(null, $this->ids['b2'], '2026-10-01 10:00:00', 'mobile', 99); // outside the 7-day window
        $r = AnalyticsService::report(7, $now);
        $this->assertSame(['sessions' => 3, 'guests' => 2, 'members' => 1, 'pages' => 16], array_intersect_key($r['totals'], array_flip(['sessions', 'guests', 'members', 'pages'])));
        $this->assertSame(['date' => '2026-11-05', 'total' => 2, 'members' => 1], $r['days'][6]);
        $this->assertSame(['date' => '2026-11-03', 'total' => 1, 'members' => 0], $r['days'][4]);
        $this->assertSame([['device' => 'mobile', 'n' => 2], ['device' => 'desktop', 'n' => 1]], $r['devices']);
        $this->assertSame($this->ids['b1'], $r['topBooks'][0]['id']);
        $this->assertSame(2, $r['topBooks'][0]['sessions']);
        $this->assertSame(1, $r['topBooks'][1]['sessions']);
    }

    public function testSettingsValidateSaveAndDriveThePublicSite(): void
    {
        $bad = $this->send('POST', '/admin/settings', ['catalog_per_page' => '1000', 'featured_book_id' => (string) $this->ids['b5'], 'contact_email' => 'nope'], $this->admin());
        $this->assertSame(303, $bad->status());
        $this->assertSame('24', Database::scalar("SELECT setting_value FROM settings WHERE setting_key = 'catalog.per_page'"), 'nothing saved on error');

        $ok = $this->send('POST', '/admin/settings', ['catalog_per_page' => '12', 'featured_book_id' => (string) $this->ids['b2'], 'contact_email' => 'rights@example.org'], $this->admin());
        $this->assertSame(303, $ok->status());
        $this->assertSame('12', Database::scalar("SELECT setting_value FROM settings WHERE setting_key = 'catalog.per_page'"));
        $this->assertContains('<option value="12" selected>', $this->send('GET', '/books')->body(), 'catalogue uses the admin page size');
        $home = $this->send('GET', '/')->body();
        $this->assertContains('cartographer-notebook', substr($home, 0, (int) strpos($home, 'id="latest') ?: strlen($home)), 'featured book from settings');
        $this->assertContains('mailto:rights@example.org', $this->send('GET', '/copyright')->body());
        $this->assertSame(1, (int) Database::scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'settings.updated'"));
        $logs = $this->send('GET', '/admin/logs?type=settings', [], $this->admin())->body();
        $this->assertContains('Changed site settings', $logs);
        $this->assertNotContains('Created a book', $logs);
    }

    public function testCoverIsReencodedStoredPrivatelyAndServedWithVisibilityRules(): void
    {
        // A PNG carrying a PHP payload after IEND: the stored file must be a clean WebP.
        $errors = ImageUploadService::cover($this->ids['b1'], $this->png(300, 450, '<?php echo "pwned"; ?>'), UPLOAD_ERR_OK, $this->admin(), '127.0.0.1', false);
        $this->assertSame([], $errors);
        $rel = (string) Database::scalar('SELECT cover_path FROM books WHERE id = :i', ['i' => $this->ids['b1']]);
        $this->assertTrue((bool) preg_match('#^private/covers/[a-f0-9]{32}\.webp$#', $rel), $rel);
        $bytes = (string) file_get_contents($this->root . '/' . $rel);
        $this->assertSame('WEBP', substr($bytes, 8, 4));
        $this->assertNotContains('<?php', $bytes);
        [$w, $h] = getimagesize($this->root . '/' . $rel);
        $this->assertSame([300, 450], [$w, $h]);

        $r = $this->send('GET', '/media/covers/' . $this->ids['b1']);
        $this->assertSame(200, $r->status());
        $this->assertSame('image/webp', $r->getHeader('Content-Type'));
        $this->assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        $this->assertContains('/media/covers/' . $this->ids['b1'] . '?v=', $this->send('GET', '/books')->body(), 'catalogue uses the uploaded cover');
        $this->assertNotContains('private/covers', $this->send('GET', '/books/gardens-river')->body(), 'storage path never exposed');

        // Members-only book: guests 404, members 200. Draft: public 404, admin preview 200.
        ImageUploadService::cover($this->ids['b4'], $this->png(), UPLOAD_ERR_OK, $this->admin(), null, false);
        ImageUploadService::cover($this->ids['b5'], $this->png(), UPLOAD_ERR_OK, $this->admin(), null, false);
        $this->assertSame(404, $this->send('GET', '/media/covers/' . $this->ids['b4'])->status());
        $this->assertSame(200, $this->send('GET', '/media/covers/' . $this->ids['b4'], [], $this->ids['u_member'])->status());
        $this->assertSame('private, max-age=3600', $this->send('GET', '/media/covers/' . $this->ids['b4'], [], $this->ids['u_member'])->getHeader('Cache-Control'));
        $this->assertSame(404, $this->send('GET', '/media/covers/' . $this->ids['b5'], [], $this->ids['u_member'])->status());
        $this->assertSame(200, $this->send('GET', '/admin/media/covers/' . $this->ids['b5'], [], $this->admin())->status());
        $this->assertSame(403, $this->send('GET', '/admin/media/covers/' . $this->ids['b5'], [], $this->ids['u_member'])->status());
        $this->assertSame(404, $this->send('GET', '/media/covers/' . $this->ids['b2'])->status(), 'no cover uploaded');

        // Replacing deletes the old file; removing clears the column and the file.
        ImageUploadService::cover($this->ids['b1'], $this->png(1600, 2400), UPLOAD_ERR_OK, $this->admin(), null, false);
        $this->assertFalse(is_file($this->root . '/' . $rel), 'previous cover deleted');
        $new = (string) Database::scalar('SELECT cover_path FROM books WHERE id = :i', ['i' => $this->ids['b1']]);
        $this->assertSame([800, 1200], array_slice(getimagesize($this->root . '/' . $new), 0, 2), 'resized to the cover box');
        $this->assertSame(303, $this->send('POST', '/admin/books/' . $this->ids['b1'] . '/cover/delete', [], $this->admin())->status());
        $this->assertSame(null, Database::scalar('SELECT cover_path FROM books WHERE id = :i', ['i' => $this->ids['b1']]));
        $this->assertFalse(is_file($this->root . '/' . $new));
        $this->assertSame(2, (int) Database::scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'book.cover_uploaded' AND entity_id = :i", ['i' => $this->ids['b1']]));
    }

    public function testImageUploadRejections(): void
    {
        $text = $this->root . '/private/temp/fake.png';
        file_put_contents($text, "not an image <?php system('id'); ?>");
        $huge = $this->png(4100, 100);
        $tiny = $this->png(20, 20);
        $cases = [
            [ImageUploadService::cover($this->ids['b1'], $text, UPLOAD_ERR_OK, $this->admin(), null, false), 'Only JPEG, PNG or WebP'],
            [ImageUploadService::cover($this->ids['b1'], $huge, UPLOAD_ERR_OK, $this->admin(), null, false), 'between 64 and'],
            [ImageUploadService::cover($this->ids['b1'], $tiny, UPLOAD_ERR_OK, $this->admin(), null, false), 'between 64 and'],
            [ImageUploadService::cover($this->ids['b1'], null, UPLOAD_ERR_NO_FILE, $this->admin(), null, false), 'Choose an image'],
            [ImageUploadService::cover($this->ids['b1'], $this->png(), UPLOAD_ERR_INI_SIZE, $this->admin(), null, false), 'larger than'],
            // A path that did not arrive through a real PHP upload is refused.
            [ImageUploadService::cover($this->ids['b1'], $this->png(), UPLOAD_ERR_OK, $this->admin(), null, true), 'upload failed'],
        ];
        foreach ($cases as $i => [$errors, $needle]) {
            $this->assertTrue(count($errors) === 1 && str_contains($errors[0], $needle), "case $i: " . json_encode($errors));
        }
        Config::set('storage.cover_max_bytes', 100);
        $this->assertContains('larger than', ImageUploadService::cover($this->ids['b1'], $this->png(), UPLOAD_ERR_OK, $this->admin(), null, false)[0]);
        Config::set('storage.cover_max_bytes', 2 * 1024 * 1024);
        $this->assertSame(null, Database::scalar('SELECT cover_path FROM books WHERE id = :i', ['i' => $this->ids['b1']]));
        $this->assertSame([], glob($this->root . '/private/covers/*') ?: []);
        $this->expectHttp(404, fn () => ImageUploadService::cover(999999, $this->png(), UPLOAD_ERR_OK, $this->admin(), null, false));
    }

    public function testAuthorPhotoServedOnlyForAuthorsWithVisibleBooks(): void
    {
        $this->assertSame([], ImageUploadService::authorPhoto($this->ids['a_salma'], $this->png(500, 500), UPLOAD_ERR_OK, $this->admin(), null, false));
        $this->assertSame([], ImageUploadService::authorPhoto($this->ids['a_ghost'], $this->png(500, 500), UPLOAD_ERR_OK, $this->admin(), null, false));
        $this->assertSame(200, $this->send('GET', '/media/authors/' . $this->ids['a_salma'])->status());
        $this->assertSame(404, $this->send('GET', '/media/authors/' . $this->ids['a_ghost'])->status(), 'author of a draft only');
        $this->assertContains('/media/authors/' . $this->ids['a_salma'] . '?v=', $this->send('GET', '/authors')->body());
        $this->assertSame(200, $this->send('GET', '/admin/media/authors/' . $this->ids['a_ghost'], [], $this->admin())->status());
        $this->assertContains('/admin/media/authors/' . $this->ids['a_ghost'], $this->send('GET', '/admin/authors/' . $this->ids['a_ghost'], [], $this->admin())->body());
    }

    public function testFilesOverviewListsVersionsWithoutPaths(): void
    {
        LibraryFixtures::attachReadyFile($this->ids['b1'], 12);
        LibraryFixtures::attachReadyFile($this->ids['b2'], 8, 'valid', 'failed');
        $html = $this->send('GET', '/admin/files', [], $this->admin())->body();
        $this->assertContains('حدائق النهر الهادئ', $html);
        $this->assertContains('Failed', $html);
        $this->assertNotContains('test/none', $html, 'storage path never shown');
        $failed = $this->send('GET', '/admin/files?status=failed', [], $this->admin())->body();
        $this->assertContains("The Cartographer&#039;s Notebook", $failed);
        $this->assertNotContains('حدائق النهر الهادئ', $failed);
    }

    private function expectHttp(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->assertTrue(false, "expected HTTP $status");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status());
        }
    }
}
