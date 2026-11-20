<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Translator;

/**
 * A member's saved language and theme (users.preferred_locale / preferred_theme),
 * applied as the dl_locale / dl_theme cookies at sign-in (Google or e-mail, any
 * device) and when changed in /account/settings. theme.js gives the cookie
 * priority over the browser's own localStorage choice.
 */
final class MemberPreferences
{
    public const THEMES = ['light', 'dark'];

    public static function applyFromDatabase(Response $response, int $userId, Request $request): Response
    {
        $row = Database::first('SELECT preferred_locale, preferred_theme FROM users WHERE id = :id', ['id' => $userId]);
        return self::apply($response, $request, $row['preferred_locale'] ?? null, $row['preferred_theme'] ?? null);
    }

    public static function apply(Response $response, Request $request, ?string $locale, ?string $theme): Response
    {
        $opts = ['expires' => time() + 31536000, 'path' => '/', 'secure' => $request->isSecure(), 'samesite' => 'Lax'];
        if (is_string($locale) && Translator::isSupported($locale)) {
            $response->cookie(Translator::COOKIE, $locale, $opts + ['httponly' => true]);
        }
        if (is_string($theme) && in_array($theme, self::THEMES, true)) {
            // Read by theme.js before first paint, so not HttpOnly (it holds no secret).
            $response->cookie('dl_theme', $theme, $opts + ['httponly' => false]);
        }
        return $response;
    }
}
