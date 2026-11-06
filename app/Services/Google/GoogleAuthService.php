<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use App\Services\Auth;

/**
 * Turns a verified Google identity into a local account and a session
 * (spec §8.3): lookup by google_sub (the stable id), create on first sign-in
 * with role "user", refresh name/email/avatar, refuse suspended accounts,
 * regenerate the session id, record an audit event (never the token).
 */
final class GoogleAuthService
{
    private const NONCE_KEY = '_google_nonce';

    /** Per-session nonce embedded in the GIS request and checked in the token. */
    public static function nonce(): string
    {
        $n = Session::get(self::NONCE_KEY);
        if (!is_string($n) || strlen($n) !== 43) {
            $n = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            Session::put(self::NONCE_KEY, $n);
        }
        return $n;
    }

    /**
     * @return array{ok:bool, reason:?string, user_id:?int, created:bool}
     */
    public static function signIn(string $credential, string $ip, GoogleIdTokenVerifier $verifier = new GoogleIdTokenVerifier()): array
    {
        $nonce = (string) Session::get(self::NONCE_KEY, '');
        try {
            $id = $verifier->verify($credential, $nonce);
        } catch (InvalidGoogleToken $e) {
            self::audit(null, 'failure', $e->getMessage(), $ip);
            return ['ok' => false, 'reason' => $e->getMessage(), 'user_id' => null, 'created' => false];
        } catch (\Throwable $e) {
            Logger::warning('Google verification error', ['error' => $e->getMessage()]);
            self::audit(null, 'failure', 'verifier_error', $ip);
            return ['ok' => false, 'reason' => 'verifier_error', 'user_id' => null, 'created' => false];
        }
        Session::forget(self::NONCE_KEY); // one nonce per sign-in (replay protection)

        $now = gmdate('Y-m-d H:i:s');
        $user = Database::first('SELECT id, status, email FROM users WHERE google_sub = :s', ['s' => $id['sub']]);
        $created = false;
        if ($user === null) {
            // Same e-mail already bound to another Google account → refuse rather than merge silently.
            if (Database::scalar('SELECT id FROM users WHERE email = :e', ['e' => $id['email']])) {
                self::audit(null, 'failure', 'email_conflict', $ip);
                return ['ok' => false, 'reason' => 'email_conflict', 'user_id' => null, 'created' => false];
            }
            Database::query(
                "INSERT INTO users (google_sub, email, name, avatar_url, email_verified_at, role, status, last_login_at, created_at, updated_at)
                 VALUES (:s, :e, :n, :a, :v, 'user', 'active', :l, :c, :u)",
                ['s' => $id['sub'], 'e' => $id['email'], 'n' => $id['name'], 'a' => $id['picture'], 'v' => $now, 'l' => $now, 'c' => $now, 'u' => $now]
            );
            $userId = Database::lastInsertId();
            $created = true;
        } else {
            $userId = (int) $user['id'];
            if ($user['status'] !== 'active') {
                self::audit($userId, 'failure', 'suspended', $ip);
                return ['ok' => false, 'reason' => 'suspended', 'user_id' => $userId, 'created' => false];
            }
            $emailTaken = $user['email'] !== $id['email']
                && Database::scalar('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $id['email'], 'id' => $userId]);
            Database::query(
                'UPDATE users SET name = :n, avatar_url = :a, email = :e, email_verified_at = COALESCE(email_verified_at, :v), last_login_at = :l, updated_at = :u WHERE id = :id',
                ['n' => $id['name'], 'a' => $id['picture'], 'e' => $emailTaken ? $user['email'] : $id['email'], 'v' => $now, 'l' => $now, 'u' => $now, 'id' => $userId]
            );
        }

        Auth::login($userId); // regenerates the session id (fixation protection) and rotates reader bindings
        self::audit($userId, 'success', $created ? 'account_created' : null, $ip);
        return ['ok' => true, 'reason' => null, 'user_id' => $userId, 'created' => $created];
    }

    public static function audit(?int $userId, string $outcome, ?string $reason, string $ip): void
    {
        try {
            Database::query(
                "INSERT INTO oauth_audit_events (user_id, provider, event_type, outcome, reason, ip_hash, request_id, created_at)
                 VALUES (:u, 'google', 'sign_in', :o, :r, :ip, :req, :c)",
                ['u' => $userId, 'o' => $outcome, 'r' => $reason, 'ip' => hash_hmac('sha256', $ip, app_key()), 'req' => Logger::requestId(), 'c' => gmdate('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Logger::warning('OAuth audit write failed', ['error' => $e->getMessage()]);
        }
    }
}
