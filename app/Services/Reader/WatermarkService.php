<?php

declare(strict_types=1);

namespace App\Services\Reader;

use App\Core\Config;
use App\Core\Database;
use GdImage;

/**
 * Bakes a visible, personalised watermark INTO the image pixels before it
 * leaves the server (spec §7.2, FR-024, TC-025). Removing a CSS overlay in the
 * browser cannot remove it: it is part of the delivered WebP.
 *
 * Label:  MAURO · M-3F9A2C1B · R-A6365F18 · 2026-11-10 10:30 UTC
 *          brand   member code  session ref  reading time (UTC)
 *
 *  - member code: privacy-safe identifier of a signed-in member, an HMAC of the
 *    user id under APP_KEY (no name, no e-mail). Guests show "GUEST".
 *  - session ref: the random pseudonym of this reading session.
 *  - Both can be traced back by an operator with `php bin/console watermark:trace <code>`.
 *
 * Drawing: a repeated diagonal pattern across the page at low opacity, plus a
 * denser footer stamp. The label is deliberately Latin letters and digits: GD
 * cannot shape Arabic script, and a script-neutral label renders identically
 * on Arabic and English books.
 * This deters and helps trace leaks; it cannot prevent screenshots.
 */
final class WatermarkService
{
    public const GUEST = 'GUEST';

    /** Privacy-safe, stable member identifier (no personal data; traceable only with APP_KEY). */
    public static function memberCode(int $userId): string
    {
        return 'M-' . strtoupper(substr(hash_hmac('sha256', 'watermark-member|' . $userId, app_key()), 0, 8));
    }

    public static function label(string $pseudonym, ?int $time = null, ?int $userId = null): string
    {
        $who = $userId !== null && $userId > 0 ? self::memberCode($userId) : self::GUEST;
        return sprintf('%s · %s · %s · %s UTC', brand_name('en'), $who, $pseudonym, gmdate('Y-m-d H:i', $time ?? time()));
    }

    public static function apply(GdImage $img, string $label, bool $thumbnail = false): void
    {
        imagealphablending($img, true);
        $w = imagesx($img);
        $h = imagesy($img);
        $font = BASE_PATH . '/resources/fonts/DejaVuSans.ttf';
        $alpha = max(0, min(127, (int) Config::get('rendering.watermark_opacity', 98)));
        // Neutral slate ink: legible on white and on tinted pages, never mistaken for book text.
        $tile = imagecolorallocatealpha($img, 64, 76, 112, $alpha);
        $stamp = imagecolorallocatealpha($img, 64, 76, 112, max(0, $alpha - 50));

        // Sizes follow the image width, so the pattern scales with every page size.
        $size = $thumbnail ? max(6, (int) round($w / 30)) : max(10, (int) round($w / 54));
        $stepY = (int) ($size * 7.5);

        $ttf = function_exists('imagettftext') && is_file($font);
        // Horizontal step = the real width of the rotated label plus a gap, so the
        // repeated copies form clean diagonal rows and never overprint each other
        // (which would make the identifying code unreadable).
        $box = $ttf ? imagettfbbox($size, 25, $font, $label) : false;
        $stepX = $box !== false
            ? (int) (max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]) + $size * 3)
            : (int) ($size * strlen($label) * 0.9);
        for ($row = 0, $y = $size * 2; $y < $h + $stepY; $row++, $y += $stepY) {
            for ($x = -$stepX + (($row % 2) * (int) ($stepX / 2)); $x < $w; $x += $stepX) {
                $ttf ? imagettftext($img, $size, 25, $x, $y, $tile, $font, $label)
                     : imagestring($img, 2, max(0, $x), max(0, $y - 10), $label, $tile);
            }
        }
        if (!$thumbnail) {
            $s = max(9, (int) round($w / 90));
            $ttf ? imagettftext($img, $s, 0, (int) ($w * 0.04), $h - (int) ($s * 1.2), $stamp, $font, $label)
                 : imagestring($img, 3, (int) ($w * 0.04), $h - 20, $label, $stamp);
        }
    }

    /**
     * Operator lookup for a code read off a leaked image (console only; never exposed over HTTP).
     * @return list<array<string, mixed>>
     */
    public static function trace(string $code): array
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^M-[0-9A-F]{8}$/', $code)) {
            foreach (Database::all('SELECT id, name, email, role, status FROM users') as $u) {
                if (hash_equals(self::memberCode((int) $u['id']), $code)) {
                    return [['type' => 'member'] + $u];
                }
            }
            return [];
        }
        if (preg_match('/^R-[0-9A-F]{8}$/', $code)) {
            return array_map(static fn (array $r): array => ['type' => 'reading_session'] + $r, Database::all(
                'SELECT rs.id, rs.user_id, u.name, u.email, rs.book_id, b.title, rs.started_at, rs.device_type, rs.pages_viewed
                   FROM reading_sessions rs
                   LEFT JOIN users u ON u.id = rs.user_id
                   JOIN books b ON b.id = rs.book_id
                  WHERE rs.reader_pseudonym = :p
                  ORDER BY rs.id',
                ['p' => $code]
            ));
        }
        return [];
    }
}
