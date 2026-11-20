<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Outgoing e-mail with an honest delivery state.
 *
 * send() returns one of:
 *   'sent'      — the SMTP server accepted the message;
 *   'disabled'  — MAIL_DRIVER is not configured: nothing was sent;
 *   'logged'    — development "log" driver: recorded, not sent;
 *   'failed'    — delivery attempted and refused / connection error.
 * Every attempt is recorded in mail_log (kind, status, short error) — never the
 * recipient, the body, links or credentials.
 */
final class Mailer
{
    /** @var (callable(string,string,string,string,?string):void)|null test double for the transport */
    private static $transport = null;

    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function driver(): string
    {
        $d = (string) Config::get('mail.driver', 'disabled');
        if ($d === 'log' && Config::get('app.env') === 'production') {
            return 'disabled';
        }
        if ($d === 'smtp' && ((string) Config::get('mail.host') === '' || !filter_var((string) Config::get('mail.from_address'), FILTER_VALIDATE_EMAIL))) {
            return 'disabled';
        }
        return in_array($d, ['smtp', 'log'], true) ? $d : 'disabled';
    }

    /** True when messages can actually leave the server. */
    public static function canDeliver(): bool
    {
        return self::$transport !== null || self::driver() === 'smtp';
    }

    public static function send(string $kind, string $to, string $subject, string $text, ?string $html = null): string
    {
        $driver = self::$transport !== null ? 'test' : self::driver();
        if ($driver === 'disabled') {
            return self::record($kind, 'disabled', null);
        }
        if ($driver === 'log') {
            Logger::info('mail.logged', ['kind' => $kind]);
            return self::record($kind, 'logged', null);
        }
        try {
            $from = (string) Config::get('mail.from_address');
            $name = (string) Config::get('mail.from_name', 'MAURO');
            if (self::$transport !== null) {
                (self::$transport)($to, $subject, $text, $html, $from);
            } else {
                (new SmtpTransport([
                    'host' => (string) Config::get('mail.host'), 'port' => (int) Config::get('mail.port', 587),
                    'encryption' => (string) Config::get('mail.encryption', 'tls'), 'username' => (string) Config::get('mail.username'),
                    'password' => (string) Config::get('mail.password'), 'timeout' => (int) Config::get('mail.timeout', 15),
                ]))->send($from, $name, $to, $subject, $text, $html);
            }
            return self::record($kind, 'sent', null);
        } catch (Throwable $e) {
            Logger::warning('mail.failed', ['kind' => $kind, 'error' => mb_substr($e->getMessage(), 0, 160)]);
            return self::record($kind, 'failed', mb_substr($e->getMessage(), 0, 250));
        }
    }

    private static function record(string $kind, string $status, ?string $error): string
    {
        try {
            Database::query('INSERT INTO mail_log (kind, status, error, created_at) VALUES (:k, :s, :e, :c)',
                ['k' => mb_substr($kind, 0, 40), 's' => $status, 'e' => $error, 'c' => gmdate('Y-m-d H:i:s')]);
        } catch (Throwable) {
            // Logging must never break the caller.
        }
        return $status;
    }
}
