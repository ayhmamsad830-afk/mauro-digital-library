<?php

declare(strict_types=1);

namespace App\Services\Reader;

use App\Core\Config;
use App\Core\Session;

/**
 * Short-lived signed access tokens for page images (spec §2, §7.5, FR-025).
 *
 *   sig = HMAC-SHA256(APP_KEY, "v1|readingSessionId|bookId|fileId|exp|bindingHash")
 *
 * - exp: a few minutes ahead (READER_TOKEN_TTL); the reader renews before expiry.
 * - bindingHash: a random secret kept in the server-side PHP session, so a
 *   copied URL does not work from another browser session, and every token
 *   dies at logout / login (the binding is rotated with the session).
 * - The token is scoped to one reading session, one book and one file
 *   version; the page number is validated against the file's page count on
 *   every request.
 */
final class ReaderTokenService
{
    private const BIND_KEY = '_reader_binding';

    /** Per-PHP-session secret (created lazily, never sent to the browser). */
    public static function binding(): string
    {
        $b = Session::get(self::BIND_KEY);
        if (!is_string($b) || strlen($b) !== 32) {
            $b = bin2hex(random_bytes(16));
            Session::put(self::BIND_KEY, $b);
        }
        return $b;
    }

    /** @return array{exp:int, sig:string} */
    public static function issue(int $readingSessionId, int $bookId, int $fileId, ?int $now = null): array
    {
        $exp = ($now ?? time()) + max(30, (int) Config::get('rendering.reader_token_ttl', 300));
        return ['exp' => $exp, 'sig' => self::sign($readingSessionId, $bookId, $fileId, $exp, self::binding())];
    }

    public static function sign(int $rs, int $bookId, int $fileId, int $exp, string $binding): string
    {
        $mac = hash_hmac('sha256', "v1|$rs|$bookId|$fileId|$exp|" . hash('sha256', $binding), app_key(), true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * @return string 'ok' | 'invalid' | 'expired'
     */
    public static function check(int $rs, int $bookId, int $fileId, int $exp, string $sig, ?int $now = null): string
    {
        $now ??= time();
        $expected = self::sign($rs, $bookId, $fileId, $exp, self::binding());
        if (!hash_equals($expected, $sig)) {
            return 'invalid';
        }
        // Reject tokens claiming an implausibly long lifetime (defence against key misuse).
        if ($exp > $now + (int) Config::get('rendering.reader_token_ttl', 300) + 5) {
            return 'invalid';
        }
        return $exp < $now ? 'expired' : 'ok';
    }
}
