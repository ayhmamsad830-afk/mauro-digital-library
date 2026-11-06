<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Runs external binaries (pdftoppm, pdfinfo, mutool) WITHOUT a shell.
 *
 * Security: the command is passed to proc_open() as an argument array, so
 * no shell is involved and arguments cannot be interpreted as shell syntax.
 * A hard timeout kills runaway renders (malformed/hostile PDFs).
 */
final class ProcessRunner
{
    public static function available(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    /**
     * @param list<string> $command binary followed by its arguments
     * @return array{exit: int, stdout: string, stderr: string, timed_out: bool}
     */
    public static function run(array $command, int $timeoutSeconds = 60, ?string $cwd = null): array
    {
        if (!self::available()) {
            throw new RuntimeException('proc_open is disabled on this host.');
        }
        if ($command === [] || $command[0] === '') {
            throw new RuntimeException('Empty command.');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . $command[0]);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string) fread($pipe, 65536);
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                $exit = -1;
                break;
            }
            // Cap captured output to avoid memory exhaustion.
            if (strlen($stdout) > 5_000_000 || strlen($stderr) > 1_000_000) {
                proc_terminate($process, 9);
                $exit = -2;
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
    }

    /** Resolve a binary name to an executable path using PATH, or null. */
    public static function which(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $binary;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
