<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Free preview for visitors without an account: guests may read only the first
 * N pages of a public book; the rest requires a (free) account.
 *
 * Enforced on the SERVER for every page and thumbnail image (real and demo), so
 * a guest can never fetch page N+1 by editing a URL. The reader UI only mirrors it.
 * N is the admin setting `reader.guest_preview_pages` (default 10).
 */
final class GuestPreview
{
    public const SETTING = 'reader.guest_preview_pages';
    public const DEFAULT_PAGES = 10;
    public const MAX_PAGES = 500;

    public static function pages(): int
    {
        $v = Setting::get(self::SETTING);
        $n = is_string($v) && ctype_digit($v) ? (int) $v : self::DEFAULT_PAGES;
        return max(1, min(self::MAX_PAGES, $n));
    }

    /** Page limit for the current viewer: null = unlimited (signed-in member). */
    public static function limit(): ?int
    {
        return Auth::user() !== null ? null : self::pages();
    }

    public static function allows(int $page): bool
    {
        $limit = self::limit();
        return $limit === null || $page <= $limit;
    }
}
