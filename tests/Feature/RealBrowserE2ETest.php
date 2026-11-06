<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProcessRunner;
use Tests\Support\PdfFactory;
use Tests\TestCase;

require_once __DIR__ . '/HttpServer.php';

/**
 * Full browser end-to-end run with demo mode OFF: a throw-away SQLite harness
 * database (dev fixtures + one member) and an isolated private storage root,
 * served by `php -S` exactly like production (public/ as document root).
 *
 *  1. tests/browser/reader-e2e.js — admin uploads a real PDF, renders it,
 *     a guest reads watermarked WebP pages through signed URLs.
 *  2. tests/browser/admin-member-check.js — every admin screen in AR/EN on
 *     phone/desktop, member favourite/review/account, cover upload,
 *     moderation, settings, suspension.
 *
 * Nothing touches MySQL, the real storage root or real sessions of other runs.
 * Skipped (not failed) when Node/Playwright or Poppler are unavailable.
 * Set E2E_SHOTS=<dir> to keep screenshots.
 */
final class RealBrowserE2ETest extends TestCase
{
    public function testReaderAdminAndMemberFlowsInARealBrowser(): void
    {
        $node = ProcessRunner::which('node');
        if (!$node || ProcessRunner::run([$node, '-e', "require.resolve('playwright')"], 15)['exit'] !== 0 || !ProcessRunner::which('pdftoppm')) {
            $this->markSkipped('node/playwright/poppler not available');
        }
        $dir = BASE_PATH . '/storage/cache/e2e-' . bin2hex(random_bytes(5));
        $db = "$dir/e2e.sqlite";
        foreach (['original-pdf', 'rendered-pages', 'thumbnails', 'temp', 'covers', 'author-photos'] as $d) {
            mkdir("$dir/storage/private/$d", 0750, true);
        }
        mkdir("$dir/storage/logs", 0750, true);
        $sessions = [];
        try {
            $env = ['APP_ENV' => 'local', 'APP_DEMO_UI' => 'false', 'DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $db, 'STORAGE_PATH' => "$dir/storage"];
            $console = static function (array $args) use ($env): array {
                $cmd = ['env'];
                foreach ($env as $k => $v) {
                    $cmd[] = "$k=$v";
                }
                return ProcessRunner::run(array_merge($cmd, [PHP_BINARY, BASE_PATH . '/bin/console'], $args), 60);
            };
            $this->assertSame(0, $console(['dev:sqlite', $db])['exit']);
            $seed = $console(['db:seed-dev', '--yes']);
            $this->assertSame(0, $seed['exit'], $seed['stdout'] . $seed['stderr']);

            $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("INSERT INTO users (google_sub, email, name, role, status) VALUES ('e2e-member', 'e2e-member@example.invalid', 'E2E Member', 'user', 'active')");
            $memberId = (int) $pdo->lastInsertId();
            $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
            $book = $pdo->query("SELECT id, slug FROM books WHERE status = 'published' AND visibility = 'public' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            $this->assertTrue((bool) $book, 'a public fixture book');

            // Session files for the two accounts (exactly what Auth stores after sign-in).
            $sid = static function (int $uid) use (&$sessions): string {
                $id = bin2hex(random_bytes(16));
                $path = BASE_PATH . "/storage/sessions/sess_$id";
                file_put_contents($path, 'user_id|i:' . $uid . ';');
                $sessions[] = $path;
                return $id;
            };
            $adminSid = $sid($adminId);
            $memberSid = $sid($memberId);

            $pdf = PdfFactory::write("$dir/work", 'e2e-book.pdf', PdfFactory::make(4, 'E2E BOOK'));
            $im = imagecreatetruecolor(400, 600);
            imagefill($im, 0, 0, imagecolorallocate($im, 30, 80, 60));
            imagepng($im, $png = "$dir/work/cover.png");
            imagedestroy($im);

            $base = HttpServer::withEnv($env, static fn () => HttpServer::base());
            $shots = (string) (getenv('E2E_SHOTS') ?: '');
            $r1 = ProcessRunner::run([$node, BASE_PATH . '/tests/browser/reader-e2e.js', $base, $adminSid, (string) $book['id'], $book['slug'], $pdf, $shots], 300);
            $this->assertSame(0, $r1['exit'], "reader-e2e failed:\n" . $r1['stdout'] . $r1['stderr']);
            $r2 = ProcessRunner::run([$node, BASE_PATH . '/tests/browser/admin-member-check.js', $base, $adminSid, $memberSid, $book['slug'], $png, $shots], 400);
            $this->assertSame(0, $r2['exit'], "admin-member-check failed:\n" . $r2['stdout'] . $r2['stderr']);
            echo "\n  [e2e] " . trim(strtok($r1['stdout'], "\n")) . ' · ' . trim(strtok($r2['stdout'], "\n")) . "\n";
        } finally {
            foreach ($sessions as $s) {
                @unlink($s);
            }
            self::rmTree($dir);
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!str_contains($dir, '/storage/cache/e2e-') || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
