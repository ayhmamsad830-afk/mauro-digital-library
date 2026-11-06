<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\ActivityLog;
use App\Services\Storage\PrivateStorage;

/**
 * Book covers and author photos (spec FR-017).
 *
 * The upload is never stored as sent: size/MIME (finfo)/dimensions are
 * checked, the image is decoded with GD and RE-ENCODED to WebP (dropping
 * metadata and any embedded payload), resized, and written under private
 * storage with a random name. Images are served only by MediaController.
 */
final class ImageUploadService
{
    public const COVERS = 'private/covers';
    public const PHOTOS = 'private/author-photos';
    private const MIMES = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_SIDE = 4000;

    /** @return list<string> errors (empty = stored) */
    public static function cover(int $bookId, ?string $tmp, int $error, int $adminId, ?string $ip, bool $fromHttpUpload = true): array
    {
        $book = Database::first('SELECT id, title, cover_path FROM books WHERE id = :id AND deleted_at IS NULL', ['id' => $bookId]);
        if (!$book) {
            throw HttpException::notFound();
        }
        return self::store($tmp, $error, $fromHttpUpload, self::COVERS, 800, 1200, $book['cover_path'],
            static function (string $rel) use ($bookId, $book, $adminId, $ip): void {
                Database::query('UPDATE books SET cover_path = :p, updated_at = :t WHERE id = :id', ['p' => $rel, 't' => gmdate('Y-m-d H:i:s'), 'id' => $bookId]);
                ActivityLog::record($adminId, 'book.cover_uploaded', 'book', $bookId, $book['title'], $ip);
            });
    }

    /** @return list<string> */
    public static function authorPhoto(int $authorId, ?string $tmp, int $error, int $adminId, ?string $ip, bool $fromHttpUpload = true): array
    {
        $author = Database::first('SELECT id, name, photo_path FROM authors WHERE id = :id', ['id' => $authorId]);
        if (!$author) {
            throw HttpException::notFound();
        }
        return self::store($tmp, $error, $fromHttpUpload, self::PHOTOS, 400, 400, $author['photo_path'],
            static function (string $rel) use ($authorId, $author, $adminId, $ip): void {
                Database::query('UPDATE authors SET photo_path = :p, updated_at = :t WHERE id = :id', ['p' => $rel, 't' => gmdate('Y-m-d H:i:s'), 'id' => $authorId]);
                ActivityLog::record($adminId, 'author.photo_uploaded', 'author', $authorId, $author['name'], $ip);
            });
    }

    public static function removeCover(int $bookId, int $adminId, ?string $ip): void
    {
        $book = Database::first('SELECT id, title, cover_path FROM books WHERE id = :id AND deleted_at IS NULL', ['id' => $bookId]) ?? throw HttpException::notFound();
        Database::query('UPDATE books SET cover_path = NULL, updated_at = :t WHERE id = :id', ['t' => gmdate('Y-m-d H:i:s'), 'id' => $bookId]);
        self::deleteFile($book['cover_path']);
        ActivityLog::record($adminId, 'book.cover_removed', 'book', $bookId, $book['title'], $ip);
    }

    /** Absolute path of a stored image, or null when missing/invalid. */
    public static function absolute(?string $relative): ?string
    {
        if (!is_string($relative) || !preg_match('#^private/(covers|author-photos)/[a-f0-9]{32}\.webp$#', $relative)) {
            return null;
        }
        $abs = PrivateStorage::path($relative);
        return is_file($abs) ? $abs : null;
    }

    /** Short cache-busting version derived from the random file name. */
    public static function version(string $relative): string
    {
        return substr(basename($relative, '.webp'), 0, 8);
    }

    /** @param callable(string):void $commit */
    private static function store(?string $tmp, int $error, bool $fromHttpUpload, string $dir, int $maxW, int $maxH, ?string $old, callable $commit): array
    {
        if ($error === UPLOAD_ERR_NO_FILE || $tmp === null || $tmp === '') {
            return [__('media.no_file')];
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return [__('media.too_large', ['mb' => self::maxMb()])];
        }
        if ($error !== UPLOAD_ERR_OK || ($fromHttpUpload && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            return [__('media.upload_failed')];
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > (int) config('storage.cover_max_bytes', 2 * 1024 * 1024)) {
            return [__('media.too_large', ['mb' => self::maxMb()])];
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = @getimagesize($tmp);
        if (!isset(self::MIMES[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            return [__('media.bad_type')];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 64 || $h < 64 || $w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            return [__('media.bad_dimensions', ['max' => self::MAX_SIDE])];
        }
        $src = match (self::MIMES[$mime]) {
            'jpeg' => @imagecreatefromjpeg($tmp),
            'png' => @imagecreatefrompng($tmp),
            'webp' => @imagecreatefromwebp($tmp),
        };
        if (!$src instanceof \GdImage) {
            return [__('media.bad_type')];
        }
        $scale = min(1.0, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $rel = $dir . '/' . PrivateStorage::randomName('webp');
        PrivateStorage::ensureDir($dir);
        $abs = PrivateStorage::path($rel);
        $ok = imagewebp($dst, $abs, 85);
        imagedestroy($dst);
        if (!$ok) {
            @unlink($abs);
            return [__('media.upload_failed')];
        }
        @chmod($abs, 0640);
        if ($fromHttpUpload) {
            @unlink($tmp);
        }
        try {
            Database::transaction(static fn () => $commit($rel));
        } catch (\Throwable $e) {
            @unlink($abs);
            throw $e;
        }
        self::deleteFile($old);
        return [];
    }

    private static function deleteFile(?string $relative): void
    {
        if (($abs = self::absolute($relative)) !== null) {
            @unlink($abs);
        }
    }

    private static function maxMb(): int
    {
        return (int) max(1, round((int) config('storage.cover_max_bytes', 2097152) / 1048576));
    }
}
