<?php

declare(strict_types=1);

namespace App\Services\Reader;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Storage\PrivateStorage;

/**
 * Serves ONE watermarked page (or thumbnail) image from private storage.
 * The master file path never leaves the server; the response body is a
 * freshly encoded WebP with the watermark drawn into its pixels.
 */
final class PageDeliveryService
{
    public static function render(int $fileId, int $page, string $variant, string $pseudonym): Response
    {
        $row = Database::first(
            'SELECT storage_path FROM book_page_renders WHERE book_file_id = :f AND variant = :v AND page_number = :n',
            ['f' => $fileId, 'v' => $variant, 'n' => $page]
        );
        if (!$row) {
            throw HttpException::notFound();
        }
        $abs = PrivateStorage::path((string) $row['storage_path']);
        $img = is_file($abs) ? @imagecreatefromwebp($abs) : false;
        if (!$img) {
            throw new HttpException(503, __('reader.load_error'));
        }
        imagepalettetotruecolor($img);
        WatermarkService::apply($img, WatermarkService::label($pseudonym), $variant === 'thumb');
        ob_start();
        imagewebp($img, null, $variant === 'thumb' ? 70 : 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return new Response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'X-Robots-Tag' => 'noindex, noimageindex',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
