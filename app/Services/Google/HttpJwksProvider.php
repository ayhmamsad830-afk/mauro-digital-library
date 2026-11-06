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
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($json['keys'] ?? null)) {
            throw new RuntimeException('Could not fetch Google signing keys.');
        }

        $keys = [];
        foreach ($json['keys'] as $k) {
            if (isset($k['kid'], $k['n'], $k['e']) && ($k['kty'] ?? '') === 'RSA') {
                $keys[(string) $k['kid']] = ['kty' => 'RSA', 'n' => (string) $k['n'], 'e' => (string) $k['e'], 'alg' => (string) ($k['alg'] ?? 'RS256')];
            }
        }
        $maxAge = preg_match('/max-age=(\d+)/', $headers['cache-control'] ?? '', $m) ? (int) $m[1] : 3600;
        @file_put_contents($cache, json_encode(['expires' => time() + min($maxAge, 86400), 'keys' => $keys]), LOCK_EX);
        return $keys;
    }
}
