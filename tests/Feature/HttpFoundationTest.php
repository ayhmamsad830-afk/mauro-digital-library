<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

require_once __DIR__ . '/HttpServer.php';

final class HttpFoundationTest extends TestCase
{
    public function testHomeRendersArabicRtlByDefault(): void
    {
        $r = HttpServer::request('GET', '/');
        $this->assertSame(200, $r['status']);
        $this->assertContains('<html lang="ar" dir="rtl"', $r['body']);
        $this->assertContains('text/html; charset=UTF-8', $r['headers']['content-type']);
    }

    public function testSecurityHeadersArePresent(): void
    {
        $h = HttpServer::request('GET', '/')['headers'];
        $this->assertSame('nosniff', $h['x-content-type-options'] ?? null);
        $this->assertSame('DENY', $h['x-frame-options'] ?? null);
        $this->assertContains("default-src 'self'", $h['content-security-policy'] ?? '');
        $this->assertContains("object-src 'none'", $h['content-security-policy'] ?? '');
        $this->assertContains("frame-ancestors 'none'", $h['content-security-policy'] ?? '');
        $this->assertContains('HttpOnly', $h['set-cookie'] ?? '', 'Session cookie must be HttpOnly');
        $this->assertContains('SameSite=Lax', $h['set-cookie'] ?? '');
    }

    public function testUnknownPageReturns404Page(): void
    {
        $r = HttpServer::request('GET', '/does-not-exist');
        $this->assertSame(404, $r['status']);
        $this->assertContains('الصفحة غير موجودة', $r['body']);
        $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, 'Error pages keep security headers');
    }

    public function testUnknownApiRouteReturnsJson404(): void
    {
        $r = HttpServer::request('GET', '/api/nothing');
        $this->assertSame(404, $r['status']);
        $this->assertContains('application/json', $r['headers']['content-type']);
        $this->assertSame(404, json_decode($r['body'], true)['error']['status']);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $r = HttpServer::request('POST', '/locale', ['Content-Type' => 'application/x-www-form-urlencoded'], 'locale=en&redirect=/');
        $this->assertSame(419, $r['status']);
        $this->assertNotContains('dl_locale', $r['headers']['set-cookie'] ?? '');
    }

    public function testCrossOriginPostIsRejectedEvenWithToken(): void
    {
        [$cookies, $token] = $this->sessionAndToken();
        $r = HttpServer::request('POST', '/locale', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://evil.example',
        ], 'locale=en&redirect=/&_token=' . $token, $cookies);
        $this->assertSame(403, $r['status']);
    }

    public function testLanguageSwitchPersistsAndFlipsDirection(): void
    {
        [$cookies, $token] = $this->sessionAndToken();
        $r = HttpServer::request('POST', '/locale', ['Content-Type' => 'application/x-www-form-urlencoded'],
            'locale=en&redirect=' . rawurlencode('//evil.example') . '&_token=' . $token, $cookies);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/', $r['headers']['location'] ?? null, 'Open redirect must be neutralised');
        $this->assertSame('en', $r['cookies']['dl_locale'] ?? null);

        $page = HttpServer::request('GET', '/', [], null, $cookies + ['dl_locale' => 'en']);
        $this->assertContains('<html lang="en" dir="ltr"', $page['body']);
        $this->assertContains('Read licensed books', $page['body']);
    }

    public function testThemeCookieRendersWithoutFlash(): void
    {
        $r = HttpServer::request('GET', '/', [], null, ['dl_theme' => 'dark']);
        $this->assertContains('data-theme="dark"', $r['body']);
        $r = HttpServer::request('GET', '/', [], null, ['dl_theme' => '"><script>']);
        $this->assertNotContains('<script>"', $r['body']);
        $this->assertNotContains('data-theme=', $r['body']);
    }

    public function testHeadRequestHasNoBody(): void
    {
        $r = HttpServer::request('HEAD', '/');
        $this->assertSame(200, $r['status']);
        $this->assertSame('', $r['body']);
    }

    public function testStaticAssetsAreServed(): void
    {
        $r = HttpServer::request('GET', '/assets/css/app.css');
        $this->assertSame(200, $r['status']);
        $this->assertContains('--c-primary', $r['body']);
    }

    public function testRobotsTxt(): void
    {
        $r = HttpServer::request('GET', '/robots.txt');
        $this->assertSame(200, $r['status']);
        $this->assertContains('User-agent: *', $r['body']);
    }

    /** @return array{0: array<string,string>, 1: string} */
    private function sessionAndToken(): array
    {
        $r = HttpServer::request('GET', '/');
        preg_match('/name="_token" value="([a-f0-9]{64})"/', $r['body'], $m);
        $this->assertTrue(isset($m[1]), 'CSRF token rendered in the language form');
        $name = (string) config('session.name');
        return [[$name => $r['cookies'][$name]], $m[1]];
    }
}
