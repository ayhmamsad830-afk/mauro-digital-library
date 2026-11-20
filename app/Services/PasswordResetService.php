<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\Mail\Mailer;

/**
 * Member password recovery by e-mail.
 *
 *  - 32 random bytes per token (base64url); only sha256(token) is stored.
 *  - Expires after TTL_MINUTES; single use (used_at); requesting again or a
 *    successful reset revokes every other token of the account.
 *  - Members (role = 'user', active) only: administrators reset from the CLI.
 *  - request() answers identically whether or not the address exists (no
 *    enumeration) and does nothing at all while e-mail delivery is disabled.
 *  - complete() sets the new hash and password_changed_at: Auth::user() then
 *    rejects every session opened before the change (all devices signed out).
 */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;

    /** @return 'unavailable'|'throttled'|'accepted' (accepted = generic "if an account exists…") */
    public static function request(string $email, string $ip): string
    {
        if (!Mailer::canDeliver()) {
            return 'unavailable';
        }
        $email = MemberPasswordAuth::normalizeEmail($email);
        $ipOk = RateLimiter::attempt('password_reset_ip', 'ip:' . $ip);
        $mailOk = $email === '' || RateLimiter::attempt('password_reset_email', 'e:' . $email);
        if (!$ipOk || !$mailOk) {
            return 'throttled';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'accepted';
        }
        $user = Database::first("SELECT id, name, email FROM users WHERE email = :e AND role = 'user' AND status = 'active'", ['e' => $email]);
        if (!$user) {
            return 'accepted';
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        Database::transaction(static function () use ($user, $token, $now, $ip): void {
            Database::query('DELETE FROM password_resets WHERE user_id = :u', ['u' => (int) $user['id']]);
            Database::query(
                'INSERT INTO password_resets (user_id, token_hash, created_at, expires_at, ip_hash) VALUES (:u, :t, :c, :x, :i)',
                ['u' => (int) $user['id'], 't' => hash('sha256', $token), 'c' => gmdate('Y-m-d H:i:s', $now),
                 'x' => gmdate('Y-m-d H:i:s', $now + self::TTL_MINUTES * 60), 'i' => hash('sha256', $ip . '|' . app_key())]
            );
        });
        $link = rtrim((string) Config::get('app.url'), '/') . '/password/reset?token=' . rawurlencode($token);
        $status = Mailer::send('password_reset', (string) $user['email'],
            __('reset.mail_subject', ['brand' => brand_name()]),
            __('reset.mail_text', ['name' => (string) $user['name'], 'link' => $link, 'minutes' => self::TTL_MINUTES, 'brand' => brand_name()]));
        Logger::info('password_reset.requested', ['user_id' => (int) $user['id'], 'mail' => $status]);
        return 'accepted';
    }

    /** @return array{id:int, user_id:int}|null a usable (unexpired, unused) token row */
    public static function find(string $token): ?array
    {
        if ($token === '' || strlen($token) > 128 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }
        $row = Database::first(
            "SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = :t AND r.used_at IS NULL AND r.expires_at > :n AND u.status = 'active' AND u.role = 'user'",
            ['t' => hash('sha256', $token), 'n' => gmdate('Y-m-d H:i:s')]
        );
        return $row ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']] : null;
    }

    /** @return string|null translation key of the error, or null on success */
    public static function complete(string $token, string $password, string $confirm, string $ip): ?string
    {
        $row = self::find($token);
        if ($row === null) {
            return 'reset.invalid_link';
        }
        $email = (string) Database::scalar('SELECT email FROM users WHERE id = :id', ['id' => $row['user_id']]);
        if (($err = MemberPasswordAuth::passwordError($password, $email)) !== null) {
            return $err;
        }
        if (!hash_equals($password, $confirm)) {
            return 'member_auth.password_mismatch';
        }
        $now = gmdate('Y-m-d H:i:s');
        $done = Database::transaction(static function () use ($row, $password, $now): bool {
            // Single use, even under concurrent submits: only one UPDATE can claim the row.
            $claimed = Database::query('UPDATE password_resets SET used_at = :n WHERE id = :id AND used_at IS NULL', ['n' => $now, 'id' => $row['id']])->rowCount();
            if ($claimed !== 1) {
                return false;
            }
            Database::query('UPDATE users SET password_hash = :h, password_must_change = 0, password_changed_at = :c, updated_at = :u WHERE id = :id',
                ['h' => AdminPasswordAuth::hash($password), 'c' => $now, 'u' => $now, 'id' => $row['user_id']]);
            Database::query('DELETE FROM password_resets WHERE user_id = :u AND id <> :id', ['u' => $row['user_id'], 'id' => $row['id']]);
            return true;
        });
        if (!$done) {
            return 'reset.invalid_link';
        }
        Logger::info('password_reset.completed', ['user_id' => $row['user_id']]);
        unset($ip);
        return null;
    }
}
