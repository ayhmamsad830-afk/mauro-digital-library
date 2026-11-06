<?php

declare(strict_types=1);

namespace App\Validators;

/** Small helpers for reading untrusted form input safely. */
final class Input
{
    public static function str(array $in, string $key, int $max = 255): string
    {
        $v = $in[$key] ?? '';
        if (!is_string($v)) {
            return '';
        }
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v); // keep \t \n \r
        $v = trim(str_replace("\r\n", "\n", $v));
        return mb_substr($v, 0, $max + 1); // +1 so length rules can still detect overflow
    }

    public static function nullable(array $in, string $key, int $max = 255): ?string
    {
        $v = self::str($in, $key, $max);
        return $v === '' ? null : $v;
    }

    /** @return list<int> unique positive ints from an array input */
    public static function ids(array $in, string $key, int $max = 50): array
    {
        $v = $in[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        $ids = [];
        foreach ($v as $x) {
            if ((is_string($x) && ctype_digit($x)) || (is_int($x) && $x > 0)) {
                $ids[] = (int) $x;
            }
        }
        return array_slice(array_values(array_unique(array_filter($ids, static fn ($i) => $i > 0))), 0, $max);
    }

    public static function bool(array $in, string $key): bool
    {
        return in_array($in[$key] ?? null, ['1', 1, true, 'on', 'true'], true);
    }
}
