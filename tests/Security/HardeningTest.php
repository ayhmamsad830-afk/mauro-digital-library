<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\LibraryRepositoryFactory;
use App\Repositories\MySqlLibraryRepository;
use Tests\Feature\HttpServer;
use Tests\Support\LibraryFixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

require_once BASE_PATH . '/tests/Feature/HttpServer.php';

/** Phase 6 hardening: headers everywhere, sitemap privacy, rate limits, redirects, methods. */
final class HardeningTest extends TestCase
{
    private array $ids = [];
    private array $saved = [];

    public function setUp(): void
    {
        TestDatabase::sqlite();
        $this->ids = LibraryFixtures::load();
        foreach (['ui.demo', 'security.rate_limits.search', 'app.url', 'app.indexable'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('ui.demo', false);
        Config::set('app.url', 'https://library.example');
        Kernel::resetRouter();
        LibraryRepositoryFactory::set(new MySqlLibraryRepository(static fn () => LibraryFixtures::NOW));
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

    private function send(string $method, string $uri, array $post = [], ?int $user = null, array $server = []): Response
    {
        unset($_SESSION['user_id']);
        if ($user !== null) {
            $_SESSION['user_id'] = $user;
        }
        Session::resetForNextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $srv = $server + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '192.0.2.10', 'HTTP_HOST' => 'localhost'];
        return (new Kernel())->handle(new Request($query, $post, $srv, ['dl_locale' => 'en']));
    }

    public function testSecurityHeadersOnEveryKindOfResponse(): void
    {
        $cases = [
            'page' => $this->send('GET', '/'),
            'book' => $this->send('GET', '/books/gardens-river'),
            '404' => $this->send('GET', '/nope'),
            'hidden book 404' => $this->send('GET', '/books/secret-draft'),
            '405' => $this->send('POST', '/', ['_token' => Csrf::token()]),
            '419' => $this->send('POST', '/logout'),
            'json 401' => $this->send('POST', '/api/reading/progress', [], null, ['HTTP_ACCEPT' => 'application/json']),
            'admin redirect' => $this->send('GET', '/admin'),
            'admin 403' => $this->send('GET', '/admin', [], $this->ids['u_member']),
            'robots' => $this->send('GET', '/robots.txt'),
            'sitemap' => $this->send('GET', '/sitemap.xml'),
            'media 404' => $this->send('GET', '/media/covers/' . $this->ids['b1']),
        ];
        foreach ($cases as $name => $r) {
            foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $h => $v) {
                $this->assertSame($v, $r->getHeader($h), "$name: $h");
            }
            $csp = (string) $r->getHeader('Content-Security-Policy');
            $this->assertContains("frame-ancestors 'none'", $csp, "$name: CSP");
            $this->assertContains("object-src 'none'", $csp, "$name: CSP");
            $this->assertNotContains('unsafe-inline', $csp, "$name: no unsafe-inline");
            $this->assertNotContains('unsafe-eval', $csp, "$name: no unsafe-eval");
            $this->assertTrue($r->hasHeader('Cache-Control'), "$name: Cache-Control");
        }
        $this->assertSame(405, $cases['405']->status());
        $this->assertSame('private, no-store', $this->send('GET', '/account', [], $this->ids['u_member'])->getHeader('Cache-Control'));
        $this->assertSame('noindex, nofollow', $this->send('GET', '/login')->getHeader('X-Robots-Tag'));
        $this->assertSame('', $this->send('HEAD', '/')->body(), 'HEAD has no body');
    }

    public function testErrorPagesNeverLeakInternals(): void
    {
        $debug = Config::get('app.debug');
        Config::set('app.debug', false);
        try {
            foreach (['/nope', '/books/secret-draft', '/read/alexandria-school'] as $p) {
                $body = $this->send('GET', $p)->body();
                foreach (['Stack trace', '.php', 'SQLSTATE', 'Exception', BASE_PATH] as $needle) {
                    $this->assertNotContains($needle, $body, "$p leaks $needle");
                }
            }
        } finally {
            Config::set('app.debug', $debug);
        }
    }

    public function testSitemapListsOnlyPublicBooksWhoeverAsks(): void
    {
        foreach ([null, $this->ids['u_member'], $this->ids['u_admin']] as $viewer) {
            $xml = $this->send('GET', '/sitemap.xml', [], $viewer)->body();
            $doc = simplexml_load_string($xml);
            $this->assertTrue($doc !== false, 'valid XML');
            $locs = array_map('strval', iterator_to_array($doc->url->loc ?? [], false));
            $locs = [];
            foreach ($doc->url as $u) {
                $locs[] = (string) $u->loc;
            }
            $this->assertContains('https://library.example/books/gardens-river', implode("\n", $locs));
            $this->assertContains('https://library.example/books/pure-data', implode("\n", $locs));
            foreach (['alexandria-school', 'secret-draft', 'archived-book', 'scheduled-book', 'deleted-book', '/account', '/admin', '/read/', '/login'] as $hidden) {
                $this->assertNotContains($hidden, implode("\n", $locs), "sitemap ($viewer) must not list $hidden");
            }
            $this->assertNotContains('hidden-cat', implode("\n", $locs), 'inactive category');
        }
        Config::set('app.indexable', true);
        $robots = $this->send('GET', '/robots.txt')->body();
        $this->assertContains('Sitemap: https://library.example/sitemap.xml', $robots);
        foreach (['/admin', '/account', '/api/', '/read/', '/media/'] as $p) {
            $this->assertContains("Disallow: $p", $robots);
        }
        Config::set('app.indexable', false);
        $this->assertContains('Disallow: /', $this->send('GET', '/robots.txt')->body());
    }

    public function testSearchIsRateLimitedPerIp(): void
    {
        Config::set('security.rate_limits.search', ['max' => 3, 'window' => 60]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->send('GET', '/search?q=garden' . $i)->status());
        }
        $this->assertSame(429, $this->send('GET', '/search?q=again')->status());
        $this->assertSame(200, $this->send('GET', '/search')->status(), 'empty search page is not limited');
        $this->assertSame(200, $this->send('GET', '/search?q=x', [], null, ['REMOTE_ADDR' => '198.51.100.99'])->status(), 'other IP unaffected');
    }

    public function testRedirectTargetsAreSameOriginOnly(): void
    {
        $cid = Config::get('google.client_id');
        Config::set('google.client_id', 'test.apps.googleusercontent.com');
        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example', "/ok\r\nSet-Cookie: x=1", 'javascript:alert(1)'] as $target) {
            $body = $this->send('GET', '/login?redirect=' . rawurlencode($target))->body();
            $this->assertContains('data-redirect="/account"', $body, "login redirect $target");
        }
        $this->assertContains('data-redirect="/books/gardens-river"', $this->send('GET', '/login?redirect=' . rawurlencode('/books/gardens-river'))->body());
        Config::set('google.client_id', $cid);
        $r = $this->send('POST', '/locale', ['locale' => 'ar', 'redirect' => 'https://evil.example/', '_token' => Csrf::token()]);
        $this->assertFalse(str_contains((string) $r->getHeader('Location'), 'evil'), 'locale switch redirect');
    }

    public function testSessionCookieIsHardened(): void
    {
        $base = HttpServer::withEnv(['APP_ENV' => 'local', 'APP_DEMO_UI' => 'true'], static fn () => HttpServer::base());
        $raw = '';
        $ch = curl_init($base . '/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        $this->assertTrue((bool) preg_match('/^Set-Cookie: dl_session=[^\r\n]*/mi', $raw, $m), 'session cookie set');
        $cookie = strtolower($m[0]);
        $this->assertContains('httponly', $cookie);
        $this->assertContains('samesite=lax', $cookie);
        $this->assertContains('path=/', $cookie);
        $this->assertNotContains('secure', $cookie, 'plain-HTTP dev server: Secure only on HTTPS (auto)');
        $this->assertFalse((bool) preg_match('/^X-Powered-By:/mi', $raw), 'no X-Powered-By (PHP version) header');
    }

    public function testClientIpIgnoresForwardedHeadersUnlessFromTrustedProxy(): void
    {
        $saved = Config::get('security.trusted_proxies');
        $req = static fn (string $remote, string $xff) => new Request([], [], ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $xff]);
        try {
            Config::set('security.trusted_proxies', []);
            $this->assertSame('203.0.113.5', $req('203.0.113.5', '1.2.3.4')->ip(), 'no proxies configured: spoofed XFF ignored');
            Config::set('security.trusted_proxies', ['10.0.0.0/8', '2001:db8::/32']);
            $this->assertSame('203.0.113.5', $req('203.0.113.5', '1.2.3.4')->ip(), 'untrusted peer: XFF ignored');
            $this->assertSame('198.51.100.7', $req('10.1.2.3', '1.2.3.4, 198.51.100.7')->ip(), 'right-most untrusted hop');
            $this->assertSame('198.51.100.7', $req('10.1.2.3', '1.2.3.4, 198.51.100.7, 10.9.9.9')->ip(), 'skips inner proxies');
            $this->assertSame('10.1.2.3', $req('10.1.2.3', 'garbage')->ip(), 'invalid header → peer');
            $this->assertSame('2001:db9::1', $req('2001:db8::5', '2001:db9::1')->ip(), 'IPv6');
            $this->assertFalse(Request::ipInList('11.0.0.1', ['10.0.0.0/8']));
            $this->assertTrue(Request::ipInList('192.168.1.200', ['192.168.1.128/25']));
            $this->assertFalse(Request::ipInList('192.168.1.100', ['192.168.1.128/25']));
        } finally {
            Config::set('security.trusted_proxies', $saved);
        }
    }
}
