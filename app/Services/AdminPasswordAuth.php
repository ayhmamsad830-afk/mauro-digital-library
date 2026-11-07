<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\ActivityLog;

/**
 * Username + password sign-in for ADMINISTRATOR accounts (production-grade, not a dev bypass).
 *
 * - Only rows with role = 'admin', status = 'active' and a password_hash can sign in this way.
 * - Hashes: Argon2id when the PHP build supports it, otherwise PASSWORD_DEFAULT (bcrypt); rehashed on login
 *   when the algorithm/cost changes. Plaintext is never stored, logged or echoed.
 * - Unknown usernames still pay one password_verify() against a dummy hash (no user-enumeration timing).
 * - Brute force: fixed-window limits per IP and per username (RateLimiter, DB-backed).
 * - Accounts are created and reset only from the CLI (bin/console admin:create / admin:reset-password);
 *   there is no public registration and no automatic promotion.
 */
final class AdminPasswordAuth
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 256;
    public const USERNAME_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]{1,62}[a-z0-9])$/';
    public const LOCAL_SUB_PREFIX = 'local:';
    public const LOCAL_EMAIL_DOMAIN = 'admin.invalid';

    public static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function validUsername(string $username): bool
    {
        return (bool) preg_match(self::USERNAME_PATTERN, $username);
    }

    /**
     * Password policy. Returns a translation key describing the first problem, or null when acceptable.
     * Length-based (NIST SP 800-63B): at least 12 characters, not the username, not trivially repetitive.
     */
    public static function policyError(string $password, string $username = ''): ?string
    {
        $len = mb_strlen($password);
        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH) {
            return 'admin_auth.policy_length';
        }
        if (trim($password) === '') {
            return 'admin_auth.policy_length';
        }
        if ($username !== '' && str_contains(mb_strtolower($password), mb_strtolower($username))) {
            return 'admin_auth.policy_username';
        }
        if (count(array_unique(mb_str_split($password))) < 5) {
            return 'admin_auth.policy_weak';
        }
        return null;
    }

    /** Generic credentials check. @return array{ok:bool, reason:string, user_id:?int, must_change:bool} */
    public static function attempt(string $username, string $password, string $ip): array
    {
        $username = self::normalizeUsername($username);
        $fail = static fn (string $reason, ?int $id = null) => ['ok' => false, 'reason' => $reason, 'user_id' => $id, 'must_change' => false];

        // Brute-force limits are consumed BEFORE verifying, for every attempt.
        $ipOk = RateLimiter::attempt('admin_login_ip', 'ip:' . $ip);
        $userOk = RateLimiter::attempt('admin_login_user', 'u:' . $username);
        if (!$ipOk || !$userOk) {
            Logger::warning('admin.login_throttled', ['ip' => $ip]);
            return $fail('throttled');
        }
        if ($username === '' || $password === '' || strlen($password) > 4 * self::MAX_LENGTH || strlen($username) > 64) {
            password_verify($password, self::dummyHash());
            return $fail('invalid');
        }

        $row = Database::first(
            'SELECT id, role, status, password_hash, password_must_change FROM users WHERE username = :u',
            ['u' => $username]
        );
        $hash = is_array($row) && is_string($row['password_hash'] ?? null) && $row['password_hash'] !== '' ? $row['password_hash'] : null;
        $verified = password_verify($password, $hash ?? self::dummyHash());

        if (!$row || $hash === null || !$verified || $row['role'] !== 'admin' || $row['status'] !== 'active') {
            if ($row) {
                ActivityLog::record((int) $row['id'], 'admin.login_failed', 'user', (int) $row['id'], 'password sign-in refused', $ip);
            }
            return $fail('invalid');
        }

        $id = (int) $row['id'];
        $now = gmdate('Y-m-d H:i:s');
        if (password_needs_rehash($hash, self::algorithm())) {
            Database::query('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => self::hash($password), 'id' => $id]);
        }
        Database::query('UPDATE users SET last_login_at = :n WHERE id = :id', ['n' => $now, 'id' => $id]);
        self::clearUserThrottle($username);
        ActivityLog::record($id, 'admin.login', 'user', $id, 'password sign-in', $ip);
        return ['ok' => true, 'reason' => 'ok', 'user_id' => $id, 'must_change' => (int) $row['password_must_change'] === 1];
    }

    /** @return string|null translation key of the error, or null on success */
    public static function changePassword(int $userId, string $current, string $new, string $confirm, ?string $ip): ?string
    {
        $row = Database::first('SELECT username, password_hash FROM users WHERE id = :id', ['id' => $userId]);
        if (!$row || !is_string($row['password_hash']) || $row['password_hash'] === '') {
            return 'admin_auth.google_only';
        }
        if (!password_verify($current, $row['password_hash'])) {
            ActivityLog::record($userId, 'admin.password_change_failed', 'user', $userId, 'current password incorrect', $ip);
            return 'admin_auth.current_wrong';
        }
        if (!hash_equals($new, $confirm)) {
            return 'admin_auth.mismatch';
        }
        if (password_verify($new, $row['password_hash'])) {
            return 'admin_auth.same_as_old';
        }
        if ($err = self::policyError($new, (string) $row['username'])) {
            return $err;
        }
        Database::query(
            'UPDATE users SET password_hash = :h, password_must_change = 0, password_changed_at = :n, updated_at = :n WHERE id = :id',
            ['h' => self::hash($new), 'n' => gmdate('Y-m-d H:i:s'), 'id' => $userId]
        );
        ActivityLog::record($userId, 'admin.password_changed', 'user', $userId, 'administrator chose a new password', $ip);
        return null;
    }

    /** CLI only. @return int new user id */
    public static function createAdmin(string $name, string $username, string $password): int
    {
        $username = self::normalizeUsername($username);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Display name is required (max 255 characters).');
        }
        if (!self::validUsername($username)) {
            throw new \InvalidArgumentException('Username must be 3–64 characters: a–z, 0–9, dot, dash or underscore, starting and ending with a letter or digit.');
        }
        if ($err = self::policyError($password, $username)) {
            throw new \InvalidArgumentException(__($err, ['min' => self::MIN_LENGTH]));
        }
        $email = $username . '@' . self::LOCAL_EMAIL_DOMAIN;
        $sub = self::LOCAL_SUB_PREFIX . $username;
        if (Database::scalar('SELECT COUNT(*) FROM users WHERE username = :u OR email = :e OR google_sub = :s', ['u' => $username, 'e' => $email, 's' => $sub])) {
            throw new \RuntimeException("An account with the username \"$username\" already exists. Use admin:reset-password instead.");
        }
        $now = gmdate('Y-m-d H:i:s');
        return Database::transaction(static function () use ($name, $username, $password, $email, $sub, $now): int {
            Database::query(
                "INSERT INTO users (google_sub, email, username, password_hash, password_must_change, name, role, status, created_at, updated_at)
                 VALUES (:s, :e, :u, :h, 1, :n, 'admin', 'active', :c, :c)",
                ['s' => $sub, 'e' => $email, 'u' => $username, 'h' => self::hash($password), 'n' => $name, 'c' => $now]
            );
            $id = Database::lastInsertId();
            ActivityLog::record($id, 'admin.created', 'user', $id, 'administrator account created from the CLI (temporary password)', null);
            return $id;
        });
    }

    /** CLI only: set a temporary password that must be changed after the next sign-in. */
    public static function resetPassword(string $username, string $password): int
    {
        $username = self::normalizeUsername($username);
        $row = Database::first("SELECT id, role FROM users WHERE username = :u", ['u' => $username]);
        if (!$row) {
            throw new \RuntimeException("No account with the username \"$username\".");
        }
        if ($row['role'] !== 'admin') {
            throw new \RuntimeException("\"$username\" is not an administrator; refusing.");
        }
        if ($err = self::policyError($password, $username)) {
            throw new \InvalidArgumentException(__($err, ['min' => self::MIN_LENGTH]));
        }
        $id = (int) $row['id'];
        Database::query(
            'UPDATE users SET password_hash = :h, password_must_change = 1, updated_at = :n WHERE id = :id',
            ['h' => self::hash($password), 'n' => gmdate('Y-m-d H:i:s'), 'id' => $id]
        );
        self::clearUserThrottle($username);
        ActivityLog::record($id, 'admin.password_reset', 'user', $id, 'temporary password set from the CLI', null);
        return $id;
    }

    private static function clearUserThrottle(string $username): void
    {
        Database::query('DELETE FROM rate_limits WHERE limiter_key = :k', ['k' => hash('sha256', 'admin_login_user|u:' . $username)]);
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash(bin2hex(random_bytes(16)), self::algorithm());
    }
}
