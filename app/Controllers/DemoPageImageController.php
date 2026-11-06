<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use GdImage;

/**
 * DEMO ONLY — synthetic page images for the reader design preview.
 *
 * No PDF is involved: each page is drawn with GD (placeholder text lines),
 * and the watermark (platform + reader pseudonym + timestamp) is drawn INTO the
 * pixels before sending, exactly as the Phase 4 WatermarkService will do for
 * real rendered pages. Routes exist only when demo mode is on.
 */
final class DemoPageImageController extends Controller
{
    private const MAX_PAGES = 24;

    public function page(Request $request): Response
    {
        [$book, $n] = $this->resolve($request);
        $img = $this->drawPage($book, $n, 900, 1272);
        $this->watermark($img, 900, 1272, 22);
        return $this->send($img, 80);
    }

    public function thumbnail(Request $request): Response
    {
        [$book, $n] = $this->resolve($request);
        $img = $this->drawPage($book, $n, 180, 254);
        $this->watermark($img, 180, 254, 7);
        return $this->send($img, 70);
    }

    /** @return array{0: array, 1: int} */
    private function resolve(Request $request): array
    {
        $this->requireDemo();
        $book = $this->library()->findBookBySlug((string) $request->param('slug'));
        $n = (int) $request->param('page');
        $max = $book ? min((int) $book['page_count'], self::MAX_PAGES) : 0;
        // Same access policy as the book page: members-only pages are never served to guests.
        if ($book === null || !\App\Services\BookAccessPolicy::canView($book) || $n < 1 || $n > $max) {
            throw HttpException::notFound();
        }
        return [$book, $n];
    }

    private function drawPage(array $book, int $n, int $w, int $h): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        $paper = imagecolorallocate($img, 253, 251, 245);
        $ink = imagecolorallocate($img, 196, 190, 178);
        $inkDark = imagecolorallocate($img, 120, 114, 104);
        imagefilledrectangle($img, 0, 0, $w, $h, $paper);

        $s = $w / 900; // scale factor
        $margin = (int) (90 * $s);
        $rtl = ($book['language'] ?? 'ar') === 'ar';

        // Heading bar + paragraphs as grey "text lines" (pseudo-random but stable per page).
        mt_srand(crc32($book['slug'] . ':' . $n));
        $y = (int) (120 * $s);
        imagefilledrectangle($img, $margin + ($rtl ? (int) (260 * $s) : 0), $y, $w - $margin - ($rtl ? 0 : (int) (260 * $s)), $y + (int) (26 * $s), $inkDark);
        $y += (int) (80 * $s);
        $lineH = (int) (34 * $s);
        while ($y < $h - (int) (150 * $s)) {
            $lines = mt_rand(4, 8);
            for ($i = 0; $i < $lines && $y < $h - (int) (150 * $s); $i++) {
                $len = $i === $lines - 1 ? mt_rand(30, 70) / 100 : mt_rand(92, 100) / 100;
                $lw = (int) (($w - 2 * $margin) * $len);
                $x1 = $rtl ? $w - $margin - $lw : $margin;
                imagefilledrectangle($img, $x1, $y, $x1 + $lw, $y + max(2, (int) (11 * $s)), $ink);
                $y += $lineH;
            }
            $y += (int) (26 * $s);
        }

        $this->text($img, (string) $n, (int) (14 * $s) ?: 6, $w / 2, $h - (int) (60 * $s), $inkDark, 0, true);
        return $img;
    }

    /**
     * Baked-in watermark: diagonal, repeated, semi-transparent. Contains no
     * personal data — a session-derived pseudonym and the view time only.
     */
    private function watermark(GdImage $img, int $w, int $h, int $size): void
    {
        $pseudonym = 'R-' . strtoupper(substr(hash_hmac('sha256', session_id() ?: 'cli', (string) config('app.key')), 0, 6));
        $label = sprintf('%s · DEMO · %s · %s', brand_name('en'), $pseudonym, gmdate('Y-m-d H:i') . ' UTC');
        $color = imagecolorallocatealpha($img, 31, 95, 91, 100); // ~22% opacity teal

        $stepY = (int) ($size * 9);
        for ($row = -1, $y = 0; $y < $h + $stepY; $row++, $y += $stepY) {
            $x = ($row % 2) * (int) ($size * 6) - (int) ($size * 4);
            $this->text($img, $label, $size, $x, $y, $color, 28, false);
        }
    }

    private function text(GdImage $img, string $text, int $size, float $x, float $y, int $color, float $angle, bool $center): void
    {
        $font = BASE_PATH . '/resources/fonts/DejaVuSans.ttf';
        if (function_exists('imagettftext') && is_file($font)) {
            if ($center) {
                $box = imagettfbbox($size, 0, $font, $text);
                $x -= ($box[2] - $box[0]) / 2;
            }
            imagettftext($img, $size, $angle, (int) $x, (int) $y, $color, $font, $text);
            return;
        }
        imagestring($img, 3, (int) $x, (int) $y, $text, $color); // fallback without FreeType
    }

    private function send(GdImage $img, int $quality): Response
    {
        ob_start();
        $webp = function_exists('imagewebp');
        $webp ? imagewebp($img, null, $quality) : imagejpeg($img, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return new Response($bytes, 200, [
            'Content-Type' => $webp ? 'image/webp' : 'image/jpeg',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'X-Robots-Tag' => 'noindex, noimageindex',
        ]);
    }
}
