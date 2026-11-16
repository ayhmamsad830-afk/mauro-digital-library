<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * E-mail + password accounts for READERS (members), alongside Google sign-in.
 *
 * - Anyone may register (free); the account is a normal 'user' (never admin).
 * - Passwords: same hashing as administrators (Argon2id when available, else bcrypt), rehashed on login.
 * - Brute force: limits per IP and per e-mail address, consumed before verifying.
 * - Unknown e-mails still pay one password_verify() (no account enumeration by timing), and both
 *   "no such account" and "wrong password" return the same generic error.
 * - Rows are stored with a reserved placeholder Google subject 'email:<random>' (real Google subjects are
 *   numeric). The e-mail is NOT verified (no mail service yet): email_verified_at stays NULL. If the owner of
 *   that address later signs in with Google, GoogleAuthService links the account to Google and removes the
 *   unverified password, so nobody can squat someone else's address.
 */
final class MemberPasswordAuth
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 256;
    public const NAME_MAX = 80;
    public const EMAIL_MAX = 190;
    public const SUB_PREFIX = 'email:';

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Validate a registration. @return array<string,string> field => translation key (empty when valid)
     */
    public static function validate(string $name, string $email, string $password, string $confirm): array
    {
        $errors = [];
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'member_auth.name_invalid';
        }
        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || str_ends_with($email, '.invalid')) {
            $errors['email'] = 'member_auth.email_invalid';
        }
        $len = mb_strlen($password);
        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH || trim($password) === '') {
            $errors['password'] = 'member_auth.password_length';
        } elseif ($email !== '' && mb_strtolower($password) === $email) {
            $errors['password'] = 'member_auth.password_is_email';
        } elseif (count(array_unique(mb_str_split($password))) < 4) {
            $errors['password'] = 'member_auth.password_weak';
        }
        if (!isset($errors['password']) && !hash_equals($password, $confirm)) {
            $errors['password_confirmation'] = 'member_auth.password_mismatch';
        }
        return $errors;
    }

    /**
     * @return array{ok:bool, errors:array<string,string>, user_id:?int, reason:?string}
     */
    public static function register(string $name, string $email, string $password, string $confirm, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $fail = static fn (array $errors, ?string $reason = null) => ['ok' => false, 'errors' => $errors, 'user_id' => null, 'reason' => $reason];

        if (!RateLimiter::attempt('member_register', 'ip:' . $ip)) {
            return $fail([], 'throttled');
        }
        $errors = self::validate($name, $email, $password, $confirm);
        if ($errors) {
            return $fail($errors);
        }
        if (Database::scalar('SELECT id FROM users WHERE email = :e', ['e' => $email])) {
            return $fail(['email' => 'member_auth.email_taken']);
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            Database::query(
                "INSERT INTO users (google_sub, email, name, password_hash, role, status, last_login_at, created_at, updated_at)
                 VALUES (:s, :e, :n, :h, 'user', 'active', :l, :c, :u)",
                [
                    's' => self::SUB_PREFIX . bin2hex(random_bytes(16)), 'e' => $email, 'n' => trim($name),
                    'h' => AdminPasswordAuth::hash($password), 'l' => $now, 'c' => $now, 'u' => $now,
                ]
            );
        } catch (\PDOException $e) {
            // Lost a race on the unique e-mail index.
            Logger::warning('member.register_failed', ['error' => $e->getMessage()]);
            return $fail(['email' => 'member_auth.email_taken']);
        }
        $id = Database::lastInsertId();
        self::audit($id, 'register', 'success', null, $ip);
        return ['ok' => true, 'errors' => [], 'user_id' => $id, 'reason' => null];
    }

    /** @return array{ok:bool, reason:string, user_id:?int} reason: ok | invalid | suspended | throttled */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $fail = static fn (string $reason, ?int $id = null) => ['ok' => false, 'reason' => $reason, 'user_id' => $id];

        $ipOk = RateLimiter::attempt('member_login_ip', 'ip:' . $ip);
        $mailOk = RateLimiter::attempt('member_login_email', 'e:' . $email);
        if (!$ipOk || !$mailOk) {
            Logger::warning('member.login_throttled', ['ip' => $ip]);
            return $fail('throttled');
        }
        if ($email === '' || $password === '' || strlen($password) > 4 * self::MAX_LENGTH || strlen($email) > 4 * self::EMAIL_MAX) {
            password_verify($password, self::dummyHash());
            return $fail('invalid');
        }

        // Members only: administrators sign in at /admin/login.
        $row = Database::first("SELECT id, status, password_hash FROM users WHERE email = :e AND role = 'user'", ['e' => $email]);
        $hash = is_array($row) && is_string($row['password_hash'] ?? null) && $row['password_hash'] !== '' ? $row['password_hash'] : null;
        $verified = password_verify($password, $hash ?? self::dummyHash());
        if (!$row || $hash === null || !$verified) {
            self::audit($row ? (int) $row['id'] : null, 'sign_in', 'failure', 'invalid', $ip);
            return $fail('invalid');
        }
        $id = (int) $row['id'];
        if ($row['status'] !== 'active') {
            self::audit($id, 'sign_in', 'failure', 'suspended', $ip);
            return $fail('suspended', $id);
        }
        if (password_needs_rehash($hash, AdminPasswordAuth::algorithm())) {
            Database::query('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => AdminPasswordAuth::hash($password), 'id' => $id]);
        }
        Database::query('UPDATE users SET last_login_at = :n WHERE id = :id', ['n' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
        Database::query('DELETE FROM rate_limits WHERE limiter_key = :k', ['k' => hash('sha256', 'member_login_email|e:' . $email)]);
        self::audit($id, 'sign_in', 'success', null, $ip);
        return ['ok' => true, 'reason' => 'ok', 'user_id' => $id];
    }

    private static function audit(?int $userId, string $event, string $outcome, ?string $reason, string $ip): void
    {
        try {
            Database::query(
                "INSERT INTO oauth_audit_events (user_id, provider, event_type, outcome, reason, ip_hash, request_id, created_at)
                 VALUES (:u, 'email', :t, :o, :r, :ip, :req, :c)",
                ['u' => $userId, 't' => $event, 'o' => $outcome, 'r' => $reason, 'ip' => hash_hmac('sha256', $ip, app_key()), 'req' => Logger::requestId(), 'c' => gmdate('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Logger::warning('Member auth audit write failed', ['error' => $e->getMessage()]);
        }
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash(bin2hex(random_bytes(16)), AdminPasswordAuth::algorithm());
    }
}
