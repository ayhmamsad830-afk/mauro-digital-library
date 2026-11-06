<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

require_once __DIR__ . '/HttpServer.php';

/**
 * Phase 2: every page renders in both languages, demo data is isolated and
 * never available in production, and the reader shell exposes no PDF.
 */
final class Phase2PagesTest extends TestCase
{
    private const DEMO = ['APP_ENV' => 'local', 'APP_DEMO_UI' => 'true'];

    private const PAGES = [
        '/', '/books', '/books/gardens-of-the-quiet-river', '/latest', '/popular', '/search?q=test',
        '/categories', '/categories/history', '/authors', '/read/the-patient-machine', '/login',
        '/demo/account', '/demo/admin', '/styleguide', '/privacy', '/terms', '/copyright',
    ];

    public function testAllPagesRenderInArabicAndEnglish(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $loc => $dir) {
                foreach (self::PAGES as $path) {
                    $r = HttpServer::request('GET', $path, [], null, ['dl_locale' => $loc]);
                    $this->assertSame(200, $r['status'], "$loc $path");
                    $this->assertContains("lang=\"$loc\" dir=\"$dir\"", $r['body'], "$loc $path direction");
                    $this->assertNotContains('errors.', $r['body'], "$loc $path untranslated key");
                    $this->assertFalse((bool) preg_match('/\b(nav|home|book|catalog|reader|admin|account|filter|sort)\.[a-z_]+\b(?![^<]*>)/', strip_tags($r['body'])), "$loc $path shows a raw translation key");
                }
            }
        });
    }

    public function testDemoBannerLabelsFictionalData(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            $r = HttpServer::request('GET', '/');
            $this->assertContains('demo-banner', $r['body']);
            $this->assertContains('خيالية', $r['body']);
        });
    }

    public function testEveryPageUsesOnlyCspSafeMarkup(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            foreach (self::PAGES as $path) {
                $body = HttpServer::request('GET', $path)['body'];
                $this->assertFalse((bool) preg_match('/\son[a-z]+\s*=/i', $body), "$path has an inline event handler");
                $this->assertFalse((bool) preg_match('/\sstyle\s*=/i', $body), "$path has an inline style attribute");
                $this->assertFalse((bool) preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $body), "$path has an inline script");
            }
        });
    }

    public function testSearchQueryIsEscaped(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            $r = HttpServer::request('GET', '/search?q=' . rawurlencode('"><script>alert(1)</script>'));
            $this->assertSame(200, $r['status']);
            $this->assertNotContains('<script>alert(1)</script>', $r['body']);
            $this->assertContains('&lt;script&gt;', $r['body']);
        });
    }

    public function testCatalogFiltersAndEmptyState(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            $r = HttpServer::request('GET', '/books?language=en&sort=title');
            $this->assertContains('The Cartographer', $r['body']);
            $this->assertNotContains('حدائق النهر الهادئ</a>', $r['body']);
            $r = HttpServer::request('GET', '/search?q=zzzz-no-match');
            $this->assertContains('empty-state', $r['body']);
            $this->assertContains('href="/search"', $r['body'], 'clear-filters link');
            $this->assertSame(404, HttpServer::request('GET', '/categories/does-not-exist')['status']);
            $this->assertSame(404, HttpServer::request('GET', '/books/Bad_Slug')['status']);
        });
    }

    public function testReaderShellExposesNoPdf(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            $body = HttpServer::request('GET', '/read/the-patient-machine')['body'];
            $this->assertContains('data-manifest', $body);
            $this->assertFalse((bool) preg_match('/\.pdf\b|pdf\.js|pdfjs|application\/pdf/i', $body), 'reader must not reference a PDF');
            $this->assertNotContains('storage/', $body);
            $this->assertContains('reader.js', $body);
            $this->assertNotContains('download', strtolower(strip_tags($body)));
        });
    }

    public function testDemoPageImagesHaveProtectiveHeaders(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            $r = HttpServer::request('GET', '/api/demo/books/the-patient-machine/pages/1');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(in_array($r['headers']['content-type'], ['image/webp', 'image/jpeg'], true));
            $this->assertContains('no-store', $r['headers']['cache-control']);
            $this->assertSame('nosniff', $r['headers']['x-content-type-options']);
            $this->assertTrue(str_starts_with($r['body'], 'RIFF') || str_starts_with($r['body'], "\xFF\xD8"), 'binary image body');
            $this->assertSame(404, HttpServer::request('GET', '/api/demo/books/the-patient-machine/pages/0')['status']);
            $this->assertSame(404, HttpServer::request('GET', '/api/demo/books/the-patient-machine/pages/25')['status']);
            $this->assertSame(404, HttpServer::request('GET', '/api/demo/books/unknown/pages/1')['status']);
        });
    }

    public function testWithoutDemoModeNoFictionalDataIsReachable(): void
    {
        HttpServer::withEnv(['APP_ENV' => 'local', 'APP_DEMO_UI' => 'false'], function (): void {
            $home = HttpServer::request('GET', '/');
            $this->assertSame(200, $home['status']);
            $this->assertNotContains('demo-banner', $home['body']);
            $this->assertNotContains('حدائق النهر الهادئ', $home['body']);
            $this->assertContains('empty-state', $home['body']);
            $this->assertContains('empty-state', HttpServer::request('GET', '/books')['body']);
            foreach (['/styleguide', '/demo/admin', '/read/the-patient-machine', '/books/gardens-of-the-quiet-river', '/api/demo/books/the-patient-machine/pages/1'] as $path) {
                $this->assertSame(404, HttpServer::request('GET', $path)['status'], "$path must be 404 without demo");
            }
            // Phase 3: the real admin exists but requires an authenticated admin.
            $admin = HttpServer::request('GET', '/admin');
            $this->assertSame(302, $admin['status']);
            $this->assertSame('/login?redirect=%2Fadmin', $admin['headers']['location']);
            $acc = HttpServer::request('GET', '/account');
            $this->assertSame(302, $acc['status']);
            $this->assertSame('/login?redirect=%2Faccount', $acc['headers']['location']);
        });
    }

    public function testDemoModeCannotBeEnabledInProduction(): void
    {
        HttpServer::withEnv(['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_DEMO_UI' => 'true'], function (): void {
            $home = HttpServer::request('GET', '/');
            $this->assertNotContains('demo-banner', $home['body']);
            $this->assertSame(404, HttpServer::request('GET', '/styleguide')['status']);
            $this->assertSame(404, HttpServer::request('GET', '/demo/admin')['status']);
            $this->assertSame(302, HttpServer::request('GET', '/admin')['status'], 'real admin requires sign-in');
        });
    }

    public function testLoginPageDoesNotFakeGoogleButton(): void
    {
        HttpServer::withEnv(self::DEMO + ['GOOGLE_CLIENT_ID' => ''], function (): void {
            $body = HttpServer::request('GET', '/login')['body'];
            $this->assertContains('google-btn" disabled', $body);
            $this->assertContains('google-unavailable', $body);
        });
    }

    public function testMobileNavigationMarkup(): void
    {
        HttpServer::withEnv(self::DEMO, function (): void {
            $body = HttpServer::request('GET', '/')['body'];
            $this->assertContains('aria-controls="site-drawer"', $body);
            $this->assertContains('aria-expanded="false"', $body);
            $this->assertContains('role="dialog" aria-modal="true"', $body);
            $this->assertContains('class="skip-link"', $body);
        });
    }

    public function testThemeAndResponsiveStylesheetsAreServed(): void
    {
        foreach (['app.css', 'components.css', 'pages.css', 'reader.css', 'admin.css'] as $css) {
            $r = HttpServer::request('GET', '/assets/css/' . $css);
            $this->assertSame(200, $r['status'], $css);
        }
        $app = HttpServer::request('GET', '/assets/css/app.css')['body'];
        $this->assertContains(':root[data-theme="dark"]', $app);
        $this->assertContains('@media (prefers-color-scheme: dark)', $app);
        $this->assertContains(':root:not([data-theme="light"])', $app);
        $this->assertContains('@media (max-width: 860px)', $app);
        $this->assertContains('@media (prefers-reduced-motion: reduce)', $app);
        $reader = HttpServer::request('GET', '/assets/css/reader.css')['body'];
        $this->assertContains('user-select: none', $reader);
        $this->assertContains('@media print', $reader);
    }
}
