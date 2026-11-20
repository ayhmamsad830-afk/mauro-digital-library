<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;

/**
 * Starts `php -S` with public/ as the document root (exactly like production
 * where only public/ is exposed) and performs real HTTP requests with cURL.
 *
 * Database isolation: a test server NEVER uses the developer's `.env` database
 * (the real library, on SQLite or MySQL). Unless a test sets DB_DRIVER itself,
 * the server gets a fresh, empty SQLite file in the temp directory. A test that
 * asks for DB_DRIVER=mysql without DB_USERNAME gets an empty user ("no database").
 */
final class HttpServer
{
    /**
     * Overrides that keep a test server away from the real databases.
     *
     * @param array<string, string> $env the test's own overrides
     * @return array<string, string>
     */
    public static function isolate(array $env, string $key): array
    {
        if (!array_key_exists('DB_DRIVER', $env)) {
            $env['DB_DRIVER'] = 'sqlite';
            $env['DB_SQLITE_PATH'] ??= self::scratchDatabase($key);
        } elseif ($env['DB_DRIVER'] === 'mysql' && !array_key_exists('DB_USERNAME', $env)) {
            $env['DB_USERNAME'] = '';
        }
        return $env;
    }

    /** A fresh, empty SQLite database (current schema) for one test server. */
    private static function scratchDatabase(string $key): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mauro-http-' . $key . '-' . getmypid() . '.sqlite';
        if (is_file($path)) {
            @unlink($path);
        }
        $pdo = \App\Core\Database::connectSqlite($path);
        $pdo->exec((string) file_get_contents(BASE_PATH . '/database/sqlite/schema.sql'));
        $pdo = null;
        self::$scratch[$key] = $path; // removed once its server is stopped (see base())
        return $path;
    }

    /** @var array<string, array{process: mixed, port: int}> one server per environment override set */
    private static array $servers = [];

    /** Environment overrides for the next request(s), e.g. ['APP_DEMO_UI' => 'false']. */
    public static array $env = [];

    /** @var array<string, string> scratch database per server key */
    private static array $scratch = [];

    public static function base(): string
    {
        $key = md5(json_encode(self::$env));
        if (!isset(self::$servers[$key])) {
            $port = random_int(20000, 40000);
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', BASE_PATH . '/public', BASE_PATH . '/public/index.php'];
            // An empty value does not survive proc_open on Windows (the child's getenv()
            // returns false), so the developer's .env would win over the override.
            // App\Core\Env reads "(empty)" as '', on every platform.
            $overrides = array_map(static fn ($v) => $v === '' ? '(empty)' : $v, self::isolate(self::$env, $key));
            $env = array_merge(getenv(), $overrides);
            $process = proc_open($cmd, [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, null, $env);
            $deadline = microtime(true) + 5;
            while (true) {
                if (@fsockopen('127.0.0.1', $port, $e, $s, 0.2)) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Built-in server did not start.');
                }
                usleep(50000);
            }
            $scratch = self::$scratch[$key] ?? null;
            register_shutdown_function(static function () use ($process, $scratch): void {
                proc_terminate($process);
                if ($scratch !== null) {
                    for ($i = 0; $i < 20 && is_file($scratch) && !@unlink($scratch); $i++) {
                        usleep(100000); // Windows: the file is released once the server has exited
                    }
                }
            });
            self::$servers[$key] = ['process' => $process, 'port' => $port];
        }
        return 'http://127.0.0.1:' . self::$servers[$key]['port'];
    }

    /** Run $fn with environment overrides applied to the server it talks to. */
    public static function withEnv(array $env, callable $fn): mixed
    {
        $previous = self::$env;
        self::$env = $env;
        try {
            return $fn();
        } finally {
            self::$env = $previous;
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string, cookies:array<string,string>}
     */
    public static function request(string $method, string $path, array $headers = [], ?string $body = null, array $cookies = []): array
    {
        $ch = curl_init(self::base() . $path);
        $hdrs = [];
        foreach ($headers as $k => $v) {
            $hdrs[] = "$k: $v";
        }
        if ($cookies) {
            $hdrs[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($cookies), $cookies));
        }
        $respHeaders = [];
        $setCookies = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders, &$setCookies): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $respHeaders[strtolower($k)] = $v;
                    if (strtolower($k) === 'set-cookie') {
                        [$pair] = explode(';', $v, 2);
                        [$ck, $cv] = array_pad(explode('=', $pair, 2), 2, '');
                        $setCookies[$ck] = $cv;
                    }
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out, 'cookies' => $setCookies];
    }
}
