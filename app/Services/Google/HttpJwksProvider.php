<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Core\Config;
use RuntimeException;

/**
 * Fetches https://www.googleapis.com/oauth2/v3/certs over TLS (certificate
 * verification ON) and caches it in storage/cache honouring Cache-Control
 * max-age. A refresh is forced once when a token names an unknown key id
 * (Google rotates keys).
 */
final class HttpJwksProvider implements JwksProvider
{
    public function keys(bool $forceRefresh = false): array
    {
        $cache = rtrim((string) Config::get('storage.cache'), '/') . '/google-jwks.json';
        if (!$forceRefresh && is_file($cache)) {
            $data = json_decode((string) file_get_contents($cache), true);
            if (is_array($data) && ($data['expires'] ?? 0) > time() && is_array($data['keys'] ?? null)) {
                return $data['keys'];
            }
        }

        $res = self::request();
        if ($res['status'] !== 200 || $res['keys'] === null) {
            // The curl error code/text helps diagnose TLS problems (e.g. 60 = no CA bundle); it holds no secrets.
            throw new RuntimeException('Could not fetch Google signing keys.'
                . ($res['errno'] !== 0 ? " (curl {$res['errno']}: {$res['error']})" : " (HTTP {$res['status']})"));
        }

        $maxAge = preg_match('/max-age=(\d+)/', $res['headers']['cache-control'] ?? '', $m) ? (int) $m[1] : 3600;
        @file_put_contents($cache, json_encode(['expires' => time() + min($maxAge, 86400), 'keys' => $res['keys']]), LOCK_EX);
        return $res['keys'];
    }

    /**
     * One live HTTPS request to the JWKS endpoint with certificate verification
     * ON, bypassing the cache. Used by keys() and by `env:check` so the check
     * exercises exactly the sign-in code path.
     *
     * @return array{status:int, errno:int, error:string, keys:?array<string,array<string,string>>, headers:array<string,string>, ms:int}
     */
    public static function request(): array
    {
        $ch = curl_init((string) Config::get('google.jwks_uri'));
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $start = hrtime(true);
        $body = curl_exec($ch);
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $keys = null;
        $json = is_string($body) ? json_decode($body, true) : null;
        if (is_array($json['keys'] ?? null)) {
            $keys = [];
            foreach ($json['keys'] as $k) {
                if (isset($k['kid'], $k['n'], $k['e']) && ($k['kty'] ?? '') === 'RSA') {
                    $keys[(string) $k['kid']] = ['kty' => 'RSA', 'n' => (string) $k['n'], 'e' => (string) $k['e'], 'alg' => (string) ($k['alg'] ?? 'RS256')];
                }
            }
        }
        return ['status' => $status, 'errno' => $errno, 'error' => $error, 'keys' => $keys, 'headers' => $headers, 'ms' => $ms];
    }
}
