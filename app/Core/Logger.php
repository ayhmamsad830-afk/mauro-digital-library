<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Daily-rotated file logger writing to storage/logs (outside the web root).
 */
final class Logger
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = (string) Config::get('storage.logs', dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s req=%s %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            self::requestId(),
            str_replace(["\r", "\n"], ' ', $message),
            $context ? ' ' . json_encode(self::redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        $file = $dir . '/app-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    /** Remove values whose keys look secret before they reach a log file. */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|key|credential|authorization/i', $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }
}
