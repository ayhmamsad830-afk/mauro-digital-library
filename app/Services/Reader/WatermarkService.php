<?php

declare(strict_types=1);

namespace App\Services\Reader;

use App\Core\Config;
use GdImage;

/**
 * Bakes a visible, personalised watermark INTO the image pixels before it
 * leaves the server (spec §7.2, FR-024, TC-025). Content: platform name +
 * pseudonymous reader id + date/time (UTC). No e-mail or other personal data.
 *
 *  - diagonal tiled lines across the page (semi-transparent)
 *  - a denser footer stamp for traceability
 *
 * The Latin brand name is used because GD cannot shape Arabic script.
 * This deters and helps trace leaks; it cannot prevent screenshots.
 */
final class WatermarkService
{
    public static function label(string $pseudonym, ?int $time = null): string
    {
        return sprintf('%s · %s · %s UTC', brand_name('en'), $pseudonym, gmdate('Y-m-d H:i', $time ?? time()));
    }

    public static function apply(GdImage $img, string $label, bool $thumbnail = false): void
    {
        imagealphablending($img, true);
        $w = imagesx($img);
        $h = imagesy($img);
        $font = BASE_PATH . '/resources/fonts/DejaVuSans.ttf';
        $alpha = max(0, min(127, (int) Config::get('rendering.watermark_opacity', 98)));
        $tile = imagecolorallocatealpha($img, 31, 95, 91, $alpha);
        $stamp = imagecolorallocatealpha($img, 31, 95, 91, max(0, $alpha - 50));

        $size = $thumbnail ? max(6, (int) round($w / 26)) : max(10, (int) round($w / 48));
        $stepY = (int) ($size * 8);
        $stepX = (int) ($size * strlen($label) * 0.62);

        $ttf = function_exists('imagettftext') && is_file($font);
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
}
