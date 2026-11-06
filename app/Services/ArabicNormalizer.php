<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Folds common Arabic spelling variants so searches tolerate diacritics and
 * letter-form differences (spec §6.4). Used both for the stored books.search_text
 * column (Phase 3) and for the user's query, so both sides match.
 */
final class ArabicNormalizer
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Tashkeel (harakat, tanween, shadda, sukun, superscript alef) + Quranic marks.
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text);
        // Tatweel (kashida).
        $text = str_replace("\u{0640}", '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي',
            'ؤ' => 'و',
            'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
