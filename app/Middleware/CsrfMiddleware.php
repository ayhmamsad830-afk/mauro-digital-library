<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Rejects state-changing requests (POST/PUT/PATCH/DELETE) that do not carry
 * the session CSRF token, either as the `_token` form field or the
 * `X-CSRF-Token` header (used by fetch() calls).
 *
 * As a second layer, when an Origin header is present it must match APP_URL's
 * origin or the request's own host.
 */
final class CsrfMiddleware implements Middleware
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE, true)) {
            return $next($request);
        }

        // When the body exceeds post_max_size PHP silently drops $_POST and $_FILES,
        // which would surface as a CSRF failure. Report the real cause instead.
        $length = (int) ($request->header('Content-Length') ?? 0);
        $postMax = \App\Services\HostCapabilityService::iniBytes((string) ini_get('post_max_size'));
        if ($length > 0 && $postMax > 0 && $length > $postMax && $request->all() === []) {
            throw new HttpException(413, __('pdf.too_large', ['max' => round(min($postMax, (int) \App\Core\Config::get('storage.pdf_max_bytes')) / 1048576, 1)]));
        }

        if (!$this->originAllowed($request)) {
            throw new HttpException(403, __('errors.csrf_origin'));
        }

        $token = $request->header('X-CSRF-Token') ?? $request->input('_token');
        if (!Csrf::validate(is_string($token) ? $token : null)) {
            throw new HttpException(419, __('errors.419.message'));
        }

        return $next($request);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            // Absent Origin (older browsers / same-origin GET-turned-POST forms):
            // rely on the token check alone.
            return $origin !== 'null';
        }
        $allowed = [];
        $appUrl = (string) Config::get('app.url', '');
        if ($appUrl !== '') {
            $allowed[] = self::originOf($appUrl);
        }
        $host = $request->header('Host');
        if ($host) {
            $allowed[] = ($request->isSecure() ? 'https://' : 'http://') . strtolower($host);
        }
        return in_array(self::originOf($origin), $allowed, true);
    }

    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $origin = strtolower($p['scheme'] . '://' . $p['host']);
        if (!empty($p['port'])) {
            $origin .= ':' . $p['port'];
        }
        return $origin;
    }
}
