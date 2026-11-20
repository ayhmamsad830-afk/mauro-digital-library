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
     * @param array<string, string>|null $env extra environment variables for the child, added to the
     *        current environment (portable replacement for `env K=V cmd`, which Windows lacks)
     * @return array{exit: int, stdout: string, stderr: string, timed_out: bool}
     */
    public static function run(array $command, int $timeoutSeconds = 60, ?string $cwd = null, ?array $env = null): array
    {
        if (!self::available()) {
            throw new RuntimeException('proc_open is disabled on this host.');
        }
        if ($command === [] || $command[0] === '') {
            throw new RuntimeException('Empty command.');
        }
        $childEnv = $env === null ? null : array_merge(getenv(), $env);
        if (PHP_OS_FAMILY === 'Windows') {
            return self::runWithFiles($command, $timeoutSeconds, $cwd, $childEnv);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $childEnv, ['bypass_shell' => true]);
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

    /**
     * Windows variant: pipes cannot be non-blocking there, so fread() on one pipe
     * blocked while the child filled the other one (deadlock: the timeout was never
     * reached). Output goes to temporary files instead; same timeout and caps.
     *
     * @param list<string> $command
     * @param array<string, string>|null $childEnv full child environment, or null to inherit
     * @return array{exit: int, stdout: string, stderr: string, timed_out: bool}
     */
    private static function runWithFiles(array $command, int $timeoutSeconds, ?string $cwd, ?array $childEnv): array
    {
        $out = tempnam(sys_get_temp_dir(), 'mpo');
        $err = tempnam(sys_get_temp_dir(), 'mpe');
        if ($out === false || $err === false) {
            throw new RuntimeException('Unable to create temporary output files.');
        }
        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']];
            $process = @proc_open($command, $descriptors, $pipes, $cwd, $childEnv, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start process: ' . $command[0]);
            }
            fclose($pipes[0]);

            $timedOut = false;
            $deadline = microtime(true) + max(1, $timeoutSeconds);
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    proc_terminate($process, 9);
                    $exit = -1;
                    break;
                }
                clearstatcache(false, $out);
                clearstatcache(false, $err);
                if ((int) @filesize($out) > 5_000_000 || (int) @filesize($err) > 1_000_000) {
                    proc_terminate($process, 9);
                    $exit = -2;
                    break;
                }
                usleep(100_000);
            }
            proc_close($process);

            return [
                'exit' => $exit,
                'stdout' => (string) file_get_contents($out, false, null, 0, 5_000_000),
                'stderr' => (string) file_get_contents($err, false, null, 0, 1_000_000),
                'timed_out' => $timedOut,
            ];
        } finally {
            @unlink($out);
            @unlink($err);
        }
    }

    /** Resolve a binary name to an executable path using PATH, or null. */
    public static function which(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }
        // Windows: "tar" is tar.exe — try the PATHEXT extensions when none is given.
        $names = [$binary];
        if (PHP_OS_FAMILY === 'Windows' && pathinfo($binary, PATHINFO_EXTENSION) === '') {
            foreach (explode(';', (string) (getenv('PATHEXT') ?: '.EXE;.BAT;.CMD')) as $ext) {
                if ($ext !== '') {
                    $names[] = $binary . strtolower($ext);
                }
            }
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            foreach ($names as $name) {
                if (is_file($name) && is_executable($name)) {
                    return $name;
                }
            }
            return null;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = rtrim($dir, '/\\') . '/' . $name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}
