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
        $row = Database::first('SELECT id, name, email, avatar_url, role, status FROM users WHERE id = :id', ['id' => $id]);
        if (!$row || $row['status'] !== 'active') {
            // Deleted or suspended account: end the session immediately.
            Session::invalidate();
            return null;
        }
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
        self::reset();
    }

    public static function logout(): void
    {
        Session::invalidate();
        self::reset();
    }
}
