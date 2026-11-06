<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use Throwable;

/**
 * Plain-PHP template renderer.
 *
 * Templates live in resources/views and receive only the data passed in.
 * Views must escape every dynamic value with e() and must never run SQL.
 */
final class View
{
    private static ?string $basePath = null;

    /** @var array<string, mixed> data available to every template (theme, demo flag, path…) */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    public static function basePath(): string
    {
        return self::$basePath ??= dirname(__DIR__, 2) . '/resources/views';
    }

    /**
     * Render a template, optionally wrapped in a layout that receives the
     * rendered template as $content.
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, $data + ['content' => $content]);
    }

    /** Render a partial (no layout). */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, $data);
    }

    private static function renderFile(string $template, array $data): string
    {
        $file = self::resolve($template);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                extract(self::$shared, EXTR_SKIP);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private static function resolve(string $template): string
    {
        // Template names are developer-controlled, but validate anyway to
        // rule out path traversal if one is ever built from input.
        if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*$#i', $template)) {
            throw new InvalidArgumentException("Invalid view name [$template].");
        }
        $file = self::basePath() . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new InvalidArgumentException("View [$template] not found.");
        }
        return $file;
    }
}
