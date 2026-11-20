<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;

/**
 * Current-user resolution from the server-side session.
 *
 * The session only stores `user_id`; role and status are re-read from the
 * database on every request, so a suspension or role change takes effect
 * immediately (spec §8.4, §13.1). Phase 5 (Google Sign-In) is the only code
 * that will call login().
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }

    /** @return array<string,mixed>|null active user row */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $id = Session::get('user_id');
        if (!is_int($id) || $id < 1) {
            return null;
        }
        $row = Database::first('SELECT id, name, email, avatar_url, role, status, password_must_change, password_changed_at FROM users WHERE id = :id', ['id' => $id]);
        if (!$row || $row['status'] !== 'active') {
            // Deleted or suspended account: end the session immediately.
            Session::invalidate();
            return null;
        }
        // A password set after this session was opened (reset by e-mail, change on another
        // device) signs this session out. Sessions from before this check existed adopt "now".
        $seen = Session::get('pw_seen');
        if (!is_int($seen)) {
            Session::put('pw_seen', $seen = time());
        }
        $changed = $row['password_changed_at'] !== null ? strtotime($row['password_changed_at'] . ' UTC') : false;
        if ($changed !== false && $changed > $seen) {
            Session::invalidate();
            return null;
        }
        unset($row['password_changed_at']);
        $row['id'] = (int) $row['id'];
        return self::$user = $row;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    /** Called by the Google sign-in flow (Phase 5) after server-side token verification. */
    public static function login(int $userId): void
    {
        Session::regenerate();
        Session::put('user_id', $userId);
        Session::put('pw_seen', time());
        self::reset();
    }

    /** The signed-in user changed their own password: keep THIS session, other devices are signed out. */
    public static function passwordChangedHere(): void
    {
        Session::regenerate();
        Session::put('pw_seen', time());
        self::reset();
    }

    public static function logout(): void
    {
        Session::invalidate();
        self::reset();
    }
}
