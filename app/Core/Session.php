<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure native PHP session wrapper.
 *
 * - HttpOnly + SameSite=Lax cookie, Secure on HTTPS (or forced in production)
 * - strict mode (rejects uninitialised session IDs → mitigates fixation)
 * - regenerate() must be called after login / privilege change
 * - flash messages for one-request notifications
 */
final class Session
{
    private static bool $started = false;

    public static function start(Request $request): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        if (PHP_SAPI === 'cli') {
            // CLI (console, in-process tests): in-memory array only, same flash ageing.
            $_SESSION = $_SESSION ?? [];
            self::$started = true;
            $_SESSION['_flash_old'] = $_SESSION['_flash_new'] ?? [];
            $_SESSION['_flash_new'] = [];
            return;
        }

        $cfg = Config::get('session', []);
        $secureSetting = $cfg['secure'] ?? 'auto';
        $secure = $secureSetting === 'auto' ? $request->isSecure() : (bool) $secureSetting;

        $savePath = (string) ($cfg['save_path'] ?? '');
        if ($savePath !== '' && is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) {
            // Deprecated (and ignored) since PHP 8.4, which uses secure defaults.
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string) (int) ($cfg['lifetime'] ?? 7200));

        session_name((string) ($cfg['name'] ?? 'dl_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        // Idle timeout: expire sessions that were inactive too long.
        $now = time();
        $lifetime = (int) ($cfg['lifetime'] ?? 7200);
        if (isset($_SESSION['_last_activity']) && ($now - (int) $_SESSION['_last_activity']) > $lifetime) {
            self::invalidate();
        }
        $_SESSION['_last_activity'] = $now;

        // Age out flash data from the previous request.
        $_SESSION['_flash_old'] = $_SESSION['_flash_new'] ?? [];
        $_SESSION['_flash_new'] = [];
    }

    /** Tests: allow the next start() to run again (simulates a new request). */
    public static function resetForNextRequest(): void
    {
        self::$started = false;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_new'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_old'][$key] ?? $_SESSION['_flash_new'][$key] ?? $default;
    }

    /** Issue a new session ID while keeping data (call after login/role change). */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf_token'], $_SESSION['_reader_binding'], $_SESSION['_reader_sessions']); // old reader tokens die with the old identity
    }

    /** Destroy all session data and issue a fresh ID (logout / account disabled). */
    public static function invalidate(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
