<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Arabic/English translation and text-direction service.
 *
 * Locale resolution (spec §2): a saved preference cookie wins; otherwise the
 * configured default (Arabic). Browser Accept-Language is intentionally NOT
 * used, because the first visit must start in Arabic.
 */
final class Translator
{
    public const COOKIE = 'dl_locale';

    private static string $locale = 'ar';

    /** @var array<string, array<string, string>> */
    private static array $lines = [];

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(Config::get('app.locales', ['ar' => 'rtl', 'en' => 'ltr']));
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::supported(), true);
    }

    public static function resolve(Request $request): string
    {
        $cookie = (string) $request->cookie(self::COOKIE, '');
        $locale = self::isSupported($cookie) ? $cookie : (string) Config::get('app.locale', 'ar');
        self::setLocale($locale);
        return self::$locale;
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = self::isSupported($locale) ? $locale : (string) Config::get('app.locale', 'ar');
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function direction(?string $locale = null): string
    {
        $locales = Config::get('app.locales', ['ar' => 'rtl', 'en' => 'ltr']);
        return $locales[$locale ?? self::$locale] ?? 'ltr';
    }

    /**
     * Translate a key with :placeholder replacement. Falls back to the fallback
     * locale, then to the key itself (so missing strings are visible in QA).
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::$locale;
        $text = self::lines($locale)[$key]
            ?? self::lines((string) Config::get('app.fallback_locale', 'en'))[$key]
            ?? $key;

        if ($replace !== []) {
            // Longest placeholder first so ':to' never clobbers ':total'.
            uksort($replace, static fn ($a, $b): int => strlen((string) $b) <=> strlen((string) $a));
            $pairs = [];
            foreach ($replace as $name => $value) {
                $pairs[':' . $name] = (string) $value;
            }
            $text = strtr($text, $pairs);
        }
        return $text;
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return isset(self::lines($locale ?? self::$locale)[$key]);
    }

    /** @return array<string, string> */
    public static function lines(string $locale): array
    {
        if (!isset(self::$lines[$locale])) {
            $file = dirname(__DIR__, 2) . '/resources/lang/' . basename($locale) . '.php';
            $data = is_file($file) ? require $file : [];
            self::$lines[$locale] = is_array($data) ? $data : [];
        }
        return self::$lines[$locale];
    }
}
