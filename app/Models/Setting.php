<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Key/value site settings (table `settings`), cached per request. */
final class Setting
{
    /** Allowed keys and their validators. Anything else is refused. */
    public const KEYS = ['catalog.per_page', 'home.featured_book_id', 'site.contact_email'];

    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = [];
                foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $r) {
                    self::$cache[$r['setting_key']] = $r['setting_value'];
                }
            } catch (\Throwable) {
                self::$cache = null; // DB unavailable: callers get defaults
                return $default;
            }
        }
        $v = self::$cache[$key] ?? null;
        return $v === null || $v === '' ? $default : (string) $v;
    }

    public static function set(string $key, ?string $value, int $adminId): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException('Unknown setting.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $exists = Database::scalar('SELECT 1 FROM settings WHERE setting_key = :k', ['k' => $key]);
        Database::query(
            $exists ? 'UPDATE settings SET setting_value = :v, updated_by = :a, updated_at = :t WHERE setting_key = :k'
                    : 'INSERT INTO settings (setting_key, setting_value, updated_by, updated_at) VALUES (:k, :v, :a, :t)',
            ['k' => $key, 'v' => $value, 'a' => $adminId, 't' => $now]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
