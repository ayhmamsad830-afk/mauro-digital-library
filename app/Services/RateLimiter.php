<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Fixed-window rate limiter stored in the `rate_limits` table (works on shared
 * hosting without Redis). Keys are hashed, so no raw IPs/tokens are stored.
 */
final class RateLimiter
{
    /** @return bool true if allowed */
    public static function attempt(string $bucket, string $identity, ?int $max = null, ?int $window = null): bool
    {
        $cfg = (array) Config::get("security.rate_limits.$bucket", []);
        $max ??= (int) ($cfg['max'] ?? 60);
        $window ??= (int) ($cfg['window'] ?? 60);
        $key = hash('sha256', $bucket . '|' . $identity);
        $now = time();
        $nowStr = gmdate('Y-m-d H:i:s', $now);

        return Database::transaction(static function () use ($key, $max, $window, $now, $nowStr): bool {
            $row = Database::first('SELECT hits, reset_at FROM rate_limits WHERE limiter_key = :k', ['k' => $key]);
            if (!$row || (string) $row['reset_at'] <= $nowStr) {
                $reset = gmdate('Y-m-d H:i:s', $now + $window);
                if ($row) {
                    Database::query('UPDATE rate_limits SET hits = 1, reset_at = :r WHERE limiter_key = :k', ['r' => $reset, 'k' => $key]);
                } else {
                    Database::query('INSERT INTO rate_limits (limiter_key, hits, reset_at) VALUES (:k, 1, :r)', ['k' => $key, 'r' => $reset]);
                }
                return true;
            }
            if ((int) $row['hits'] >= $max) {
                return false;
            }
            Database::query('UPDATE rate_limits SET hits = hits + 1 WHERE limiter_key = :k', ['k' => $key]);
            return true;
        });
    }

    /** Throw 429 when over the limit. */
    public static function enforce(string $bucket, string $identity): void
    {
        if (!self::attempt($bucket, $identity)) {
            $window = (int) Config::get("security.rate_limits.$bucket.window", 60);
            throw new HttpException(429, __('errors.429.message'), ['Retry-After' => (string) $window]);
        }
    }
}
