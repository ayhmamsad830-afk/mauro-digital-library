<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal, dependency-free .env loader.
 *
 * Real environment variables (set by the web server / hosting panel) always
 * win over values in the .env file, so production hosts can inject secrets
 * without a file on disk.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $value = self::parseValue(trim(substr($line, $pos + 1)));

            $external = getenv($key);
            self::$values[$key] = $external !== false ? $external : $value;
        }
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        if (($quote === '"' || $quote === "'") && ($end = strpos($raw, $quote, 1)) !== false) {
            $value = substr($raw, 1, $end - 1);
            return $quote === '"' ? str_replace(['\\n', '\\"'], ["\n", '"'], $value) : $value;
        }
        // Strip inline comments for unquoted values.
        $hash = strpos($raw, ' #');
        return $hash === false ? $raw : rtrim(substr($raw, 0, $hash));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = self::$values[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /** For tests only. */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}
