<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Core\Config;
use RuntimeException;

/**
 * Private file storage OUTSIDE the web document root (spec §7.5, §13.3).
 *
 * The database stores paths RELATIVE to the storage root
 * (e.g. "private/original-pdf/12/5f0c…e1.pdf"). They are never sent to the
 * browser. Every resolution rejects traversal and anything outside
 * "private/", so a tampered DB value cannot read arbitrary files.
 */
final class PrivateStorage
{
    public const ORIGINALS = 'private/original-pdf';
    public const PAGES = 'private/rendered-pages';
    public const THUMBS = 'private/thumbnails';
    public const TEMP = 'private/temp';

    public static function root(): string
    {
        return rtrim((string) Config::get('storage.root'), '/');
    }

    /** Absolute path for a relative storage path (validated). */
    public static function path(string $relative): string
    {
        if (!preg_match('#^private/[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-.]+)*$#', $relative) || str_contains($relative, '..')) {
            throw new RuntimeException('Invalid storage path.');
        }
        return self::root() . '/' . $relative;
    }

    public static function ensureDir(string $relative): string
    {
        $abs = self::path($relative);
        if (!is_dir($abs) && !@mkdir($abs, 0750, true) && !is_dir($abs)) {
            throw new RuntimeException('Cannot create private directory.');
        }
        return $abs;
    }

    /** New random file name (never derived from user input). */
    public static function randomName(string $ext): string
    {
        return bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** Move a file into private storage with restrictive permissions. */
    public static function moveInto(string $source, string $relative, bool $isUploadedFile): void
    {
        $dest = self::path($relative);
        self::ensureDir(dirname($relative));
        $ok = $isUploadedFile ? @move_uploaded_file($source, $dest) : @rename($source, $dest);
        if (!$ok && !$isUploadedFile) {
            $ok = @copy($source, $dest) && @unlink($source);
        }
        if (!$ok) {
            throw new RuntimeException('Could not store the file.');
        }
        @chmod($dest, 0640);
    }

    /** Recursively delete a directory inside private storage. */
    public static function deleteDir(string $relative): void
    {
        $abs = self::path($relative);
        if (!is_dir($abs)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($abs);
    }

    public static function freeMb(): int
    {
        $free = @disk_free_space(self::root());
        return $free === false ? PHP_INT_MAX : (int) floor($free / 1048576);
    }
}
