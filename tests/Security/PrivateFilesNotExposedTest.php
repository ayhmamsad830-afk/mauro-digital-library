<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Feature\HttpServer;
use Tests\TestCase;

require_once dirname(__DIR__) . '/Feature/HttpServer.php';

/**
 * TC-023 (foundation part): nothing outside public/ is reachable over HTTP,
 * and secrets never appear in a response.
 */
final class PrivateFilesNotExposedTest extends TestCase
{
    public function testSensitivePathsAreNotServed(): void
    {
        $key = (string) config('app.key');
        $paths = [
            '/.env', '/../.env', '/%2e%2e/.env', '/.env.example', '/.htaccess',
            '/storage/logs/', '/storage/private/original-pdf/', '/../storage/private/temp/',
            '/app/bootstrap.php', '/../app/bootstrap.php', '/config/database.php',
            '/composer.json', '/vendor/autoload.php', '/database/migrations/0001_create_users_table.sql',
            '/bin/console',
        ];
        foreach ($paths as $path) {
            $r = HttpServer::request('GET', $path);
            $this->assertTrue(in_array($r['status'], [400, 403, 404], true), "$path returned {$r['status']}");
            $this->assertNotContains('APP_KEY', $r['body'], "$path leaked env");
            $this->assertNotContains($key, $r['body'], "$path leaked APP_KEY");
            $this->assertNotContains('DB_PASSWORD', $r['body'], "$path leaked env");
        }
    }

    public function testPublicDirectoryContainsNoPrivateMaterial(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/public', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $ext = strtolower($file->getExtension());
            $this->assertFalse(in_array($ext, ['pdf', 'env', 'sql', 'log', 'bak'], true), 'Forbidden file in public/: ' . $file->getPathname());
        }
        $this->assertFalse(is_dir(BASE_PATH . '/public/pdfjs'), 'PDF.js must not exist (spec §2, §7.1)');
        $this->assertFalse(is_dir(BASE_PATH . '/public/storage'), 'Storage must not be linked into public/');
    }

    public function testNoRouteReturnsPdf(): void
    {
        foreach (\App\Core\Kernel::router()->routes() as $route) {
            $this->assertFalse((bool) preg_match('#/(download|file|pdf)(/|$)#i', $route->path()), 'Forbidden route: ' . $route->path());
        }
    }

    /** Phase 4: an original PDF and rendered pages in private storage are unreachable over HTTP. */
    public function testStoredOriginalsAndRenderedPagesAreNeverServed(): void
    {
        $dir = BASE_PATH . '/storage/private/original-pdf/sentinel-test';
        $pages = BASE_PATH . '/storage/private/rendered-pages/sentinel-test';
        @mkdir($dir, 0750, true);
        @mkdir($pages, 0750, true);
        $name = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents("$dir/$name", "%PDF-1.4 SENTINEL-ORIGINAL\n%%EOF");
        file_put_contents("$pages/p0001.webp", 'RIFFxxxxWEBPSENTINEL-PAGE');
        try {
            $paths = [
                "/storage/private/original-pdf/sentinel-test/$name", "/private/original-pdf/sentinel-test/$name",
                "/original-pdf/sentinel-test/$name", "/../storage/private/original-pdf/sentinel-test/$name",
                '/%2e%2e/storage/private/original-pdf/sentinel-test/' . $name, "/assets/../../storage/private/original-pdf/sentinel-test/$name",
                '/storage/private/rendered-pages/sentinel-test/p0001.webp', '/rendered-pages/sentinel-test/p0001.webp',
                '/api/books/1/file', '/api/books/1/download', '/api/books/1/pdf', '/download/1',
            ];
            foreach ($paths as $path) {
                $r = HttpServer::request('GET', $path);
                $this->assertTrue(in_array($r['status'], [400, 403, 404], true), "$path returned {$r['status']}");
                $this->assertNotContains('SENTINEL', $r['body'], "$path leaked private storage");
                $this->assertNotContains('%PDF', $r['body'], "$path returned PDF bytes");
            }
        } finally {
            @unlink("$dir/$name");
            @unlink("$pages/p0001.webp");
            @rmdir($dir);
            @rmdir($pages);
        }
    }

    public function testApiNeverDeclaresPdfResponses(): void
    {
        $src = '';
        foreach (glob(BASE_PATH . '/app/Controllers/{,*/}*.php', GLOB_BRACE) as $f) {
            $src .= file_get_contents($f);
        }
        $this->assertFalse((bool) preg_match('#application/pdf#i', $src), 'no controller emits application/pdf');
        $this->assertFalse((bool) preg_match('#readfile\s*\(|fpassthru\s*\(#', $src), 'no controller streams raw files');
    }
}
