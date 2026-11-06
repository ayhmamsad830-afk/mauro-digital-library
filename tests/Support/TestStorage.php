<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;

/** Isolated private-storage root per test (never the real storage). */
final class TestStorage
{
    private static ?string $previous = null;

    public static function create(): string
    {
        $root = BASE_PATH . '/storage/cache/test-storage-' . bin2hex(random_bytes(5));
        foreach (['original-pdf', 'rendered-pages', 'thumbnails', 'temp'] as $d) {
            mkdir("$root/private/$d", 0750, true);
        }
        self::$previous = (string) Config::get('storage.root');
        Config::set('storage.root', $root);
        return $root;
    }

    public static function destroy(string $root): void
    {
        if (self::$previous !== null) {
            Config::set('storage.root', self::$previous);
        }
        if (!str_contains($root, '/storage/cache/test-storage-') || !is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($root);
    }

    /** All files under the root (relative paths). @return list<string> */
    public static function files(string $root, string $sub = 'private'): array
    {
        $dir = "$root/$sub";
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($root) + 1);
        }
        sort($out);
        return $out;
    }
}
