<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Adds baseline security headers to every response.
 * Controllers may pre-set a stricter header (e.g. Cache-Control on page
 * images); existing values are not overwritten.
 */
final class SecurityHeadersMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
            'Content-Security-Policy' => self::csp(),
        ];
        foreach ($defaults as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response->header($name, $value);
            }
        }

        if ($request->isSecure() && Config::get('security.hsts', true)) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Account/admin/API responses must never be cached by shared caches.
        if (!$response->hasHeader('Cache-Control')) {
            $path = $request->path();
            $private = str_starts_with($path, '/account') || str_starts_with($path, '/admin') || str_starts_with($path, '/api/');
            $response->header('Cache-Control', $private ? 'private, no-store' : 'no-cache, private');
        }

        if (!Config::get('app.indexable', true) || self::isPrivatePath($request->path())) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    public static function csp(): string
    {
        // Google Identity Services needs its script, style and iframe origins.
        $gis = 'https://accounts.google.com';
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' $gis/gsi/client",
            "style-src 'self' $gis/gsi/style",
            "img-src 'self' data: blob: https://*.googleusercontent.com",
            "font-src 'self'",
            "connect-src 'self' $gis/gsi/",
            "frame-src $gis/gsi/",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    private static function isPrivatePath(string $path): bool
    {
        foreach (['/account', '/admin', '/api/', '/read/', '/login'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
