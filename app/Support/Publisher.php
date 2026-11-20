<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Slug;

/**
 * Publishers are the distinct values of books.publisher (no separate table, no duplication).
 * Their URL slug must be stable on every server, with or without ext-intl:
 *   - Latin-only names → readable slug ("dar-al-saqi"),
 *   - anything else (Arabic…) → "p-" + 10 hex chars of sha256(normalised name).
 */
final class Publisher
{
    public static function normalize(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public static function slug(string $name): string
    {
        $name = self::normalize($name);
        if ($name !== '' && preg_match('/^[\x20-\x7E]+$/', $name)) {
            $slug = Slug::from($name);
            if ($slug !== 'item') {
                return $slug;
            }
        }
        return 'p-' . substr(hash('sha256', mb_strtolower($name)), 0, 10);
    }
}
