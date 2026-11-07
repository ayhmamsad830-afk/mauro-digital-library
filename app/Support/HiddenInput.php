<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Read a secret from an interactive terminal without echoing it (CLI only).
 *
 * Refuses non-interactive input (pipes, redirects, CI) so a password can never be supplied through a file,
 * a shell history entry or a command-line argument. The value is returned to the caller only — it is never
 * printed or logged.
 *  - Windows: PowerShell `Read-Host -AsSecureString` on the same console.
 *  - Linux/macOS: `stty -echo` around a line read.
 */
final class HiddenInput
{
    public static function interactive(): bool
    {
        return PHP_SAPI === 'cli' && function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    public static function ask(string $prompt): string
    {
        if (!self::interactive()) {
            throw new RuntimeException('Refusing: passwords must be typed in an interactive terminal (no pipes or redirects).');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $script = '[Console]::OutputEncoding=[Text.Encoding]::UTF8; '
                . '$s = Read-Host -AsSecureString -Prompt ' . self::psQuote($prompt) . '; '
                . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
                . 'try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)) } '
                . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';
            $proc = proc_open(['powershell.exe', '-NoProfile', '-Command', $script], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($proc)) {
                throw new RuntimeException('Unable to open a hidden prompt (PowerShell).');
            }
            $value = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            if (proc_close($proc) !== 0) {
                throw new RuntimeException('Hidden prompt cancelled.');
            }
            return $value;
        }
        fwrite(STDOUT, $prompt . ': ');
        $stty = static function (string $mode): void {
            $p = proc_open(['stty', $mode], [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($p)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
            }
        };
        $stty('-echo');
        try {
            $line = fgets(STDIN);
        } finally {
            $stty('echo');
            fwrite(STDOUT, PHP_EOL);
        }
        if ($line === false) {
            throw new RuntimeException('Hidden prompt cancelled.');
        }
        return rtrim($line, "\r\n");
    }

    /** Plain (visible) question for non-secret values such as a display name. */
    public static function line(string $prompt, string $default = ''): string
    {
        fwrite(STDOUT, $prompt . ($default !== '' ? " [$default]" : '') . ': ');
        $line = fgets(STDIN);
        $value = $line === false ? '' : trim($line);
        return $value === '' ? $default : $value;
    }

    private static function psQuote(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
