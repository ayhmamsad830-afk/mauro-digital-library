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
use App\Services\Pdf\PdfUploadService;
use App\Services\Pdf\RenderService;
use App\Services\Reader\ReaderTokenService;
use App\Services\Storage\PrivateStorage;
use Tests\Support\LibraryFixtures;
use Tests\Support\PdfFactory;
use Tests\Support\TestDatabase;
use Tests\Support\TestStorage;
use Tests\TestCase;

/**
 * End-to-end protected reader through the real Kernel: sessions, signed
 * tokens, policy checks, watermark in pixels, rate limiting, progress and
 * admin upload endpoints. Rendering uses real Poppler + GD.
 */
final class ProtectedReaderTest extends TestCase
{
    private array $ids = [];
    private string $root = '';
    private array $saved = [];
    private array $files = [];

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        $this->root = TestStorage::create();
        foreach (['ui.demo', 'rendering.page_width', 'security.rate_limits.reader_pages'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('ui.demo', false);
        Config::set('rendering.page_width', 700);
        Kernel::resetRouter();
        LibraryRepositoryFactory::set(new MySqlLibraryRepository());
        $_SESSION = [];
        // Real rendered versions for a public book (b1) and a members-only book (b4).
        $this->files['b1'] = $this->ingest($this->ids['b1'], 3, 'PUBLIC BOOK');
        $this->files['b4'] = $this->ingest($this->ids['b4'], 2, 'MEMBERS BOOK');
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        Kernel::resetRouter();
        $_SESSION = [];
        TestStorage::destroy($this->root);
        TestDatabase::release();
    }

    private function ingest(int $bookId, int $pages, string $label): int
    {
        $path = PdfFactory::write($this->root . '/work', bin2hex(random_bytes(4)) . '.pdf', PdfFactory::make($pages, $label));
        [$fileId, $errors] = PdfUploadService::store($bookId, $path, 'Original-Secret-Name.pdf', UPLOAD_ERR_OK, $this->ids['u_admin'], null, false);
        $this->assertSame([], $errors);
        $job = (int) Database::scalar('SELECT id FROM book_render_jobs WHERE book_file_id = :f', ['f' => $fileId]);
        RenderService::claim($job, 'test');
        $this->assertTrue((new RenderService())->process($job)['ok']);
        return $fileId;
    }

    private function send(string $method, string $uri, array $body = [], ?int $userId = null, bool $csrf = true, bool $json = true, array $server = []): Response
    {
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
        Session::resetForNextRequest();
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $srv = $server + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ACCEPT' => 'application/json'];
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
                $post = $body;
            }
        }
        return (new Kernel())->handle(new Request($query, $post, $srv, ['dl_locale' => 'en'], [], $raw));
    }

    private function startSession(int $bookId, ?int $userId = null): array
    {
        $r = $this->send('POST', '/api/reader/sessions', ['book_id' => $bookId], $userId);
        $this->assertSame(200, $r->status(), $r->body());
        return json_decode($r->body(), true);
    }

    private function url(array $m, int $page, string $kind = 'pageUrl'): string
    {
        $t = $m['token'];
        return str_replace('{n}', (string) $page, $m[$kind]) . "?rs={$t['rs']}&exp={$t['exp']}&sig={$t['sig']}";
    }

    private function master(int $fileId, int $page, string $variant = 'page'): \GdImage
    {
        $rel = (string) Database::scalar('SELECT storage_path FROM book_page_renders WHERE book_file_id = :f AND variant = :v AND page_number = :p', ['f' => $fileId, 'v' => $variant, 'p' => $page]);
        return imagecreatefromwebp(PrivateStorage::path($rel));
    }

    /** Fraction of pixels whose colour differs noticeably between two same-size images. */
    private function diff(\GdImage $a, \GdImage $b, int $y0 = 0, ?int $y1 = null): float
    {
        $w = imagesx($a);
        $y1 ??= imagesy($a);
        $changed = $total = 0;
        for ($y = $y0; $y < $y1; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $ca = imagecolorat($a, $x, $y);
                $cb = imagecolorat($b, $x, $y);
                $d = abs((($ca >> 16) & 255) - (($cb >> 16) & 255)) + abs((($ca >> 8) & 255) - (($cb >> 8) & 255)) + abs(($ca & 255) - ($cb & 255));
                $changed += $d > 24 ? 1 : 0;
                $total++;
            }
        }
        return $changed / max(1, $total);
    }

    // ------------------------------------------------------------------ shell

    public function testReaderShellExposesNoPdfPathOrFileName(): void
    {
        $r = $this->send('GET', '/read/gardens-river');
        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertContains('/api/reader/sessions', $body);
        foreach (['.pdf', 'original-pdf', 'Original-Secret-Name', 'private/', 'pdf.js', 'pdfjs', 'storage_path'] as $needle) {
            $this->assertNotContains($needle, $body, "reader page leaks: $needle");
        }
        $this->assertNotContains('download', strtolower(strip_tags($body)));
    }

    // --------------------------------------------------------------- sessions

    public function testSessionRequiresCsrfAndReturnsManifestWithoutSecrets(): void
    {
        $this->assertSame(419, $this->send('POST', '/api/reader/sessions', ['book_id' => $this->ids['b1']], null, false)->status());
        $before = (int) Database::scalar('SELECT reads_count FROM books WHERE id = :b', ['b' => $this->ids['b1']]);
        $m = $this->startSession($this->ids['b1']);
        $this->assertSame(3, $m['pageCount']);
        $this->assertSame(1, $m['fileVersion']);
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $m['token']['sig']));
        $json = json_encode($m);
        foreach (['.pdf', 'original', 'private/', 'storage', 'Secret'] as $needle) {
            $this->assertFalse(stripos($json, $needle) !== false, "manifest leaks: $needle");
        }
        $this->assertSame($before + 1, (int) Database::scalar('SELECT reads_count FROM books WHERE id = :b', ['b' => $this->ids['b1']]), 'one real reading session counted');
        $again = $this->startSession($this->ids['b1']);
        $this->assertSame($m['token']['rs'], $again['token']['rs'], 'same browser session reuses the reading session');
        $this->assertSame($before + 1, (int) Database::scalar('SELECT reads_count FROM books WHERE id = :b', ['b' => $this->ids['b1']]), 'page reloads are not counted twice');
    }

    // ------------------------------------------------------------ page images

    public function testValidTokenServesWatermarkedWebpWithProtectiveHeaders(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $r = $this->send('GET', $this->url($m, 2));
        $this->assertSame(200, $r->status(), $r->body());
        $this->assertSame('image/webp', $r->getHeader('Content-Type'));
        $this->assertContains('no-store', (string) $r->getHeader('Cache-Control'));
        $this->assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        $this->assertSame('same-origin', $r->getHeader('Cross-Origin-Resource-Policy'));
        $this->assertTrue(str_starts_with($r->body(), 'RIFF') && substr($r->body(), 8, 4) === 'WEBP');
        $this->assertFalse(str_contains($r->body(), '%PDF'));

        $served = imagecreatefromstring($r->body());
        $master = $this->master($this->files['b1'], 2);
        $this->assertSame([imagesx($master), imagesy($master)], [imagesx($served), imagesy($served)]);
        $rel = (string) Database::scalar("SELECT storage_path FROM book_page_renders WHERE book_file_id = :f AND variant = 'page' AND page_number = 2", ['f' => $this->files['b1']]);
        $this->assertFalse($r->body() === file_get_contents(PrivateStorage::path($rel)), 'the unwatermarked master is never sent');
        $whole = $this->diff($master, $served);
        $this->assertTrue($whole > 0.03, sprintf('watermark changes the pixels (%.1f%% changed)', $whole * 100));
        $footer = $this->diff($master, $served, imagesy($master) - 60, imagesy($master));
        $this->assertTrue($footer > 0.01, sprintf('footer stamp present (%.1f%%)', $footer * 100));
        $this->assertSame(1, (int) Database::scalar('SELECT pages_viewed FROM reading_sessions WHERE id = :id', ['id' => $m['token']['rs']]));
    }

    public function testWatermarkIsPersonalisedPerReadingSession(): void
    {
        $a = $this->startSession($this->ids['b1']);
        $imgA = imagecreatefromstring($this->send('GET', $this->url($a, 1))->body());
        $_SESSION = [];
        $b = $this->startSession($this->ids['b1']);
        $this->assertFalse($a['token']['rs'] === $b['token']['rs']);
        $imgB = imagecreatefromstring($this->send('GET', $this->url($b, 1))->body());
        $p = Database::all('SELECT reader_pseudonym FROM reading_sessions WHERE id IN (:a, :b)', ['a' => $a['token']['rs'], 'b' => $b['token']['rs']]);
        $this->assertFalse($p[0]['reader_pseudonym'] === $p[1]['reader_pseudonym']);
        $this->assertTrue($this->diff($imgA, $imgB) > 0.001, 'different readers receive different pixels');
    }

    public function testThumbnailsAreWatermarkedToo(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $r = $this->send('GET', $this->url($m, 3, 'thumbUrl'));
        $this->assertSame(200, $r->status());
        $thumb = imagecreatefromstring($r->body());
        $this->assertSame(220, imagesx($thumb));
        $this->assertTrue($this->diff($this->master($this->files['b1'], 3, 'thumb'), $thumb) > 0.02);
    }

    // ---------------------------------------------------------- authorization

    public function testMissingTamperedOrMisusedTokensAreRejected(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $b1 = $this->ids['b1'];
        $this->assertSame(403, $this->send('GET', "/api/books/$b1/pages/1")->status(), 'no token');
        $t = $m['token'];
        $bad = substr($t['sig'], 0, -1) . ($t['sig'][-1] === 'A' ? 'B' : 'A');
        $this->assertSame(403, $this->send('GET', "/api/books/$b1/pages/1?rs={$t['rs']}&exp={$t['exp']}&sig=$bad")->status(), 'tampered signature');
        $this->assertSame(403, $this->send('GET', "/api/books/$b1/pages/1?rs={$t['rs']}&exp=" . ($t['exp'] + 1) . "&sig={$t['sig']}")->status(), 'altered expiry');
        $other = $this->ids['b2'];
        $this->assertSame(503, $this->send('GET', "/api/books/$other/pages/1?rs={$t['rs']}&exp={$t['exp']}&sig={$t['sig']}")->status(), 'public book without a ready version');
        $b4Session = $this->startSession($this->ids['b4'], $this->ids['u_member']);
        $this->assertSame(403, $this->send('GET', "/api/books/{$this->ids['b4']}/pages/1?rs={$t['rs']}&exp={$t['exp']}&sig={$t['sig']}")->status(), 'token for book A used on book B');
        $this->assertSame(404, $this->send('GET', $this->url($m, 0))->status(), 'page 0');
        $this->assertSame(404, $this->send('GET', $this->url($m, 4))->status(), 'page beyond the end');
        $this->assertSame(200, $this->send('GET', $this->url($b4Session, 1))->status(), 'control: member token works');
    }

    public function testExpiredAndOverlongTokensAreRejected(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $rs = $m['token']['rs'];
        $old = ReaderTokenService::issue($rs, $this->ids['b1'], $this->files['b1'], time() - 3600);
        $r = $this->send('GET', "/api/books/{$this->ids['b1']}/pages/1?rs=$rs&exp={$old['exp']}&sig={$old['sig']}");
        $this->assertSame(401, $r->status(), 'expired token');
        $exp = time() + 86400;
        $long = ReaderTokenService::sign($rs, $this->ids['b1'], $this->files['b1'], $exp, ReaderTokenService::binding());
        $this->assertSame(403, $this->send('GET', "/api/books/{$this->ids['b1']}/pages/1?rs=$rs&exp=$exp&sig=$long")->status(), 'lifetime beyond TTL');
        // Expired reading session (server side) also stops page delivery.
        Database::query("UPDATE reading_sessions SET expires_at = '2000-01-01 00:00:00' WHERE id = :id", ['id' => $rs]);
        $this->assertSame(401, $this->send('GET', $this->url($m, 1))->status());
    }

    public function testTokensAreBoundToTheBrowserSession(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $url = $this->url($m, 1);
        $this->assertSame(200, $this->send('GET', $url)->status());
        $_SESSION = []; // another browser / cleared cookies / logged out
        $this->assertSame(403, $this->send('GET', $url)->status(), 'copied URL does not work in another session');
    }

    public function testMembersOnlyPagesFollowTheSamePolicy(): void
    {
        $b4 = $this->ids['b4'];
        $this->assertSame(404, $this->send('POST', '/api/reader/sessions', ['book_id' => $b4])->status(), 'guest cannot start');
        $this->assertSame(404, $this->send('GET', '/read/alexandria-school')->status(), 'guest reader shell');
        $m = $this->startSession($b4, $this->ids['u_member']);
        $this->assertSame(200, $this->send('GET', $this->url($m, 1))->status(), 'member reads');
        // Member account suspended mid-session: the very next page is refused.
        Database::query("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $this->ids['u_member']]);
        $this->assertSame(404, $this->send('GET', $this->url($m, 2))->status(), 'suspended → treated as guest');
    }

    public function testUnpublishingOrReplacingAfterTokenIssueTakesEffectImmediately(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $this->assertSame(200, $this->send('GET', $this->url($m, 1))->status());
        // New version activated while the reader is open → 409 so the client reloads.
        $this->ingest($this->ids['b1'], 2, 'NEW EDITION');
        $this->assertSame(409, $this->send('GET', $this->url($m, 1))->status());
        // Unpublished → invisible → 404.
        Database::query("UPDATE books SET status = 'draft' WHERE id = :b", ['b' => $this->ids['b1']]);
        $this->assertSame(404, $this->send('GET', $this->url($m, 1))->status());
    }

    public function testRateLimitOnPageRequests(): void
    {
        Config::set('security.rate_limits.reader_pages', ['max' => 3, 'window' => 60]);
        $m = $this->startSession($this->ids['b1']);
        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(200, $this->send('GET', $this->url($m, 1))->status());
        }
        $r = $this->send('GET', $this->url($m, 2));
        $this->assertSame(429, $r->status());
        $this->assertSame('60', $r->getHeader('Retry-After'));
    }

    public function testManifestAndRenewEndpoints(): void
    {
        $m = $this->startSession($this->ids['b1']);
        $t = $m['token'];
        $man = $this->send('GET', "/api/books/{$this->ids['b1']}/reader-manifest?rs={$t['rs']}&exp={$t['exp']}&sig={$t['sig']}");
        $this->assertSame(200, $man->status());
        $this->assertFalse(stripos($man->body(), 'pdf') !== false);
        $renew = $this->send('POST', '/api/reader/sessions/renew', ['rs' => $t['rs'], 'book_id' => $this->ids['b1']]);
        $this->assertSame(200, $renew->status(), $renew->body());
        $nt = json_decode($renew->body(), true)['token'];
        $this->assertSame(200, $this->send('GET', "/api/books/{$this->ids['b1']}/pages/1?rs={$nt['rs']}&exp={$nt['exp']}&sig={$nt['sig']}")->status());
        $_SESSION = [];
        $this->assertSame(403, $this->send('POST', '/api/reader/sessions/renew', ['rs' => $t['rs'], 'book_id' => $this->ids['b1']])->status(), 'cannot renew a session started elsewhere');
    }

    // --------------------------------------------------------------- progress

    public function testMemberProgressIsStoredAndValidated(): void
    {
        $b1 = $this->ids['b1'];
        $this->assertSame(401, $this->send('POST', '/api/reading/progress', ['book_id' => $b1, 'page' => 2, 'file_version' => 1])->status(), 'guest');
        $member = $this->ids['u_member'];
        $ok = $this->send('POST', '/api/reading/progress', ['book_id' => $b1, 'page' => 2, 'file_version' => 1], $member);
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertSame(66.67, json_decode($ok->body(), true)['percent']);
        $this->assertSame(422, $this->send('POST', '/api/reading/progress', ['book_id' => $b1, 'page' => 9, 'file_version' => 1], $member)->status(), 'out of range');
        $this->assertSame(409, $this->send('POST', '/api/reading/progress', ['book_id' => $b1, 'page' => 1, 'file_version' => 7], $member)->status(), 'stale version');
        $this->assertSame(419, $this->send('POST', '/api/reading/progress', ['book_id' => $b1, 'page' => 1, 'file_version' => 1], $member, false)->status(), 'CSRF');
        $this->send('POST', '/api/reading/progress', ['book_id' => $b1, 'page' => 3, 'file_version' => 1], $member);
        $this->assertSame(1, (int) Database::scalar('SELECT COUNT(*) FROM reading_progress WHERE user_id = :u', ['u' => $member]), 'one row per user and book');
        $get = json_decode($this->send('GET', "/api/reading/progress/$b1", [], $member)->body(), true);
        $this->assertSame([3, 3, 1], [$get['page'], $get['total'], $get['file_version']]);
        $m = $this->startSession($b1, $member);
        $this->assertSame(3, $m['progress']['page'], 'session start returns saved progress for members');
        $this->assertTrue($m['member']);
    }

    // ------------------------------------------------------------ admin upload

    public function testAdminUploadEndpointAuthorizationAndErrors(): void
    {
        $b2 = $this->ids['b2'];
        $this->assertSame(403, $this->send('POST', "/admin/books/$b2/replace-pdf", [], $this->ids['u_member'], true, false)->status(), 'member forbidden');
        $_SESSION = [];
        $this->assertSame(419, $this->send('POST', "/admin/books/$b2/replace-pdf", [], $this->ids['u_admin'], false, false)->status(), 'CSRF');
        $r = $this->send('POST', "/admin/books/$b2/replace-pdf", [], $this->ids['u_admin'], true, false);
        $this->assertSame(303, $r->status());
        $page = $this->send('GET', "/admin/books/$b2", [], $this->ids['u_admin']);
        $this->assertContains(__('pdf.no_file'), $page->body(), 'no file → flash error');
        $big = $this->send('POST', "/admin/books/$b2/replace-pdf", [], $this->ids['u_admin'], true, false, ['CONTENT_LENGTH' => (string) (1024 ** 3)]);
        $this->assertSame(413, $big->status(), 'body beyond post_max_size');
    }

    public function testAdminProcessNowRendersAndShowsVersions(): void
    {
        $b2 = $this->ids['b2'];
        $path = PdfFactory::write($this->root . '/work', 'n.pdf', PdfFactory::make(2, 'PROCESS NOW'));
        [$fileId] = PdfUploadService::store($b2, $path, 'n.pdf', UPLOAD_ERR_OK, $this->ids['u_admin'], null, false);
        $edit = $this->send('GET', "/admin/books/$b2", [], $this->ids['u_admin']);
        $this->assertContains('Process now', $edit->body());
        $this->assertNotContains('original-pdf', $edit->body(), 'admin UI shows no storage paths');
        $r = $this->send('POST', "/admin/books/$b2/files/$fileId/process", [], $this->ids['u_admin'], true, false);
        $this->assertSame(303, $r->status());
        $this->assertSame('ready', Database::scalar('SELECT render_status FROM book_files WHERE id = :f', ['f' => $fileId]));
        $this->assertSame($fileId, (int) Database::scalar('SELECT current_file_id FROM books WHERE id = :b', ['b' => $b2]));
    }
}
