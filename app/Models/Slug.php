<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** ASCII URL slugs (Arabic titles are transliterated) with uniqueness per table. */
final class Slug
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const TABLES = ['books', 'authors', 'categories'];

    public static function from(string $text): string
    {
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($t) {
                $text = (string) $t->transliterate($text);
            }
        }
        $text = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text));
        $text = trim($text, '-');
        return substr($text, 0, 120) ?: 'item';
    }

    /** Append -2, -3… until unused in $table (ignoring $exceptId). */
    public static function unique(string $table, string $base, ?int $exceptId = null): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unsupported table');
        }
        $base = trim(substr($base, 0, 120), '-') ?: 'item';
        $slug = $base;
        for ($i = 2; self::exists($table, $slug, $exceptId); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    public static function exists(string $table, string $slug, ?int $exceptId = null): bool
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unsupported table');
        }
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM $table WHERE slug = :slug AND id <> :except",
            ['slug' => $slug, 'except' => $exceptId ?? 0]
        );
    }
}
