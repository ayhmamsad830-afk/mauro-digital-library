<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;

/**
 * Starts `php -S` with public/ as the document root (exactly like production
 * where only public/ is exposed) and performs real HTTP requests with cURL.
 */
final class HttpServer
{
    /** @var array<string, array{process: mixed, port: int}> one server per environment override set */
    private static array $servers = [];

    /** Environment overrides for the next request(s), e.g. ['APP_DEMO_UI' => 'false']. */
    public static array $env = [];

    public static function base(): string
    {
        $key = md5(json_encode(self::$env));
        if (!isset(self::$servers[$key])) {
            $port = random_int(20000, 40000);
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', BASE_PATH . '/public', BASE_PATH . '/public/index.php'];
            $env = array_merge(getenv(), self::$env);
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
            register_shutdown_function(static fn () => proc_terminate($process));
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
