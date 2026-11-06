<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Core\Config;

/**
 * Server-side verification of a Google Identity Services ID token (JWT, RS256)
 * — spec §8.2. Nothing from the browser is trusted until this passes.
 *
 * Checks: 3-part JWT · header alg = RS256 (rejects "none"/HS256 confusion) ·
 * known kid (one forced JWKS refresh on unknown kid) · RSA-SHA256 signature
 * with Google's public key · iss ∈ {accounts.google.com, https://accounts.google.com}
 * · aud = our client ID · exp/iat/nbf with 60 s leeway · nonce = the one we
 * issued for this browser session · email present and verified · sub present ·
 * optional hosted-domain (hd) allow-list.
 *
 * Implemented with ext-openssl because Composer packages cannot be installed in
 * this build environment; the logic mirrors google/apiclient's verifyIdToken.
 */
final class GoogleIdTokenVerifier
{
    public const LEEWAY = 60;

    public function __construct(private readonly JwksProvider $jwks = new HttpJwksProvider())
    {
    }

    /**
     * @return array{sub:string,email:string,name:string,picture:?string,email_verified:bool}
     * @throws InvalidGoogleToken
     */
    public function verify(string $jwt, string $expectedNonce, ?int $now = null): array
    {
        $now ??= time();
        $clientId = (string) Config::get('google.client_id', '');
        if ($clientId === '') {
            throw new InvalidGoogleToken('not_configured');
        }
        if (strlen($jwt) > 8192 || substr_count($jwt, '.') !== 2) {
            throw new InvalidGoogleToken('malformed');
        }
        [$h64, $p64, $s64] = explode('.', $jwt);
        $header = json_decode(self::b64($h64), true);
        $claims = json_decode(self::b64($p64), true);
        $sig = self::b64($s64);
        if (!is_array($header) || !is_array($claims) || $sig === '') {
            throw new InvalidGoogleToken('malformed');
        }
        if (($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw new InvalidGoogleToken('bad_alg');
        }

        $keys = $this->jwks->keys();
        if (!isset($keys[$header['kid']])) {
            $keys = $this->jwks->keys(true);
        }
        $jwk = $keys[$header['kid']] ?? null;
        if ($jwk === null) {
            throw new InvalidGoogleToken('unknown_key');
        }
        $pem = self::jwkToPem($jwk['n'], $jwk['e']);
        if (openssl_verify("$h64.$p64", $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidGoogleToken('bad_signature');
        }

        if (!in_array($claims['iss'] ?? null, (array) Config::get('google.issuers', []), true)) {
            throw new InvalidGoogleToken('bad_issuer');
        }
        $aud = $claims['aud'] ?? null;
        if (!(is_string($aud) && hash_equals($clientId, $aud))) {
            throw new InvalidGoogleToken('bad_audience');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::LEEWAY) {
            throw new InvalidGoogleToken('expired');
        }
        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + self::LEEWAY)) {
            throw new InvalidGoogleToken('bad_iat');
        }
        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + self::LEEWAY)) {
            throw new InvalidGoogleToken('not_yet_valid');
        }
        if ($expectedNonce === '' || !is_string($claims['nonce'] ?? null) || !hash_equals($expectedNonce, $claims['nonce'])) {
            throw new InvalidGoogleToken('bad_nonce');
        }
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 255) {
            throw new InvalidGoogleToken('no_sub');
        }
        $email = $claims['email'] ?? null;
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$verified) {
            throw new InvalidGoogleToken('email_unverified');
        }
        $allowedHd = (array) Config::get('google.allowed_hd', []);
        if ($allowedHd !== [] && !in_array($claims['hd'] ?? null, $allowedHd, true)) {
            throw new InvalidGoogleToken('domain_not_allowed');
        }
        $picture = $claims['picture'] ?? null;

        return [
            'sub' => $sub,
            'email' => mb_strtolower($email),
            'name' => mb_substr(is_string($claims['name'] ?? null) ? trim($claims['name']) : '', 0, 255),
            'picture' => is_string($picture) && preg_match('#^https://[a-z0-9.-]+\.googleusercontent\.com/#i', $picture) ? mb_substr($picture, 0, 2048) : null,
            'email_verified' => true,
        ];
    }

    public static function b64(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    /** Build a PEM public key from RSA modulus/exponent (base64url, big-endian). */
    public static function jwkToPem(string $n64, string $e64): string
    {
        $n = self::b64($n64);
        $e = self::b64($e64);
        if ($n === '' || $e === '') {
            throw new InvalidGoogleToken('bad_key');
        }
        $int = static function (string $bytes): string {
            if (ord($bytes[0]) > 0x7f) {
                $bytes = "\x00" . $bytes;
            }
            return "\x02" . self::derLength(strlen($bytes)) . $bytes;
        };
        $rsaKey = "\x30" . self::derLength(strlen($int($n) . $int($e))) . $int($n) . $int($e);
        $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption OID + NULL
        $bitString = "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $spki = "\x30" . self::derLength(strlen($algo . $bitString)) . $algo . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
