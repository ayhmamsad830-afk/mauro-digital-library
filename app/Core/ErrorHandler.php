<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Converts PHP errors into exceptions, logs every unexpected failure with a
 * request id, and renders a safe error page (HTML) or JSON body (API).
 *
 * Stack traces are only shown when APP_DEBUG=true in a non-production env.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', self::debug() ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // respects the @ operator
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            $request = Request::fromGlobals();
            self::render($e, $request)->send();
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/html; charset=UTF-8');
                    echo self::plainPage(500);
                }
            }
        });
    }

    public static function debug(): bool
    {
        return (bool) Config::get('app.debug', false) && Config::get('app.env') !== 'production';
    }

    public static function render(Throwable $e, Request $request): Response
    {
        $status = $e instanceof HttpException ? $e->status() : (self::isDatabaseUnavailable($e) ? 503 : 500);

        if ($status >= 500) {
            Logger::error(get_class($e) . ': ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
        }

        $publicMessage = $e instanceof HttpException && $e->getMessage() !== '' ? $e->getMessage() : null;

        if ($request->wantsJson()) {
            $payload = [
                'error' => [
                    'status' => $status,
                    'message' => $publicMessage ?? self::statusText($status),
                    'request_id' => Logger::requestId(),
                ],
            ];
            if (self::debug() && !$e instanceof HttpException) {
                $payload['error']['debug'] = ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
            }
            $response = Response::json($payload, $status);
        } else {
            try {
                $template = in_array($status, [404, 500], true) ? "errors/$status" : 'errors/generic';
                $html = View::render($template, [
                    'status' => $status,
                    'message' => $publicMessage,
                    'requestId' => Logger::requestId(),
                    'exception' => self::debug() && !$e instanceof HttpException ? $e : null,
                    'pageTitle' => __('errors.' . $status . '.title') !== 'errors.' . $status . '.title'
                        ? __('errors.' . $status . '.title')
                        : self::statusText($status),
                ]);
            } catch (Throwable $viewError) {
                Logger::error('Error view failed: ' . $viewError->getMessage());
                $html = self::plainPage($status);
            }
            $response = Response::html($html, $status);
        }

        if ($e instanceof HttpException) {
            foreach ($e->headers() as $name => $value) {
                $response->header($name, $value);
            }
        }
        return $response;
    }

    /** Connection-level PDO failures (server down, bad credentials, gone away) → 503, not 500. */
    public static function isDatabaseUnavailable(Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException) {
                $code = (string) ($x->errorInfo[1] ?? $x->getCode());
                if (in_array($code, ['2002', '2003', '2005', '2006', '2013', '1044', '1045', '1049'], true)
                    || preg_match('/SQLSTATE\[(HY000|08\d{3})\] \[(2002|2003|2005|2006|1044|1045|1049)\]/', $x->getMessage())) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function statusText(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Payload Too Large',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
            default => $status >= 500 ? 'Server Error' : 'Error',
        };
    }

    /** Last-resort page that depends on nothing but PHP. */
    public static function plainPage(int $status): string
    {
        $text = htmlspecialchars(self::statusText($status), ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $status . '</title><body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem">'
            . '<h1>' . $status . '</h1><p>حدث خطأ غير متوقع.</p><p>' . $text . '</p><p><a href="/">الرئيسية / Home</a></p></body></html>';
    }
}
