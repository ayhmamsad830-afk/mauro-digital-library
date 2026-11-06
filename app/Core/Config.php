<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only configuration repository loaded from config/*.php.
 * Access with dot notation: Config::get('app.name').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function loadDirectory(string $dir): void
    {
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $data = require $file;
            if (is_array($data)) {
                self::$items[$name] = $data;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }
}
