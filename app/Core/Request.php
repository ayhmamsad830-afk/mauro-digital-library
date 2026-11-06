<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish wrapper around the PHP superglobals.
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?array $jsonBody = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES, null);
    }

    /**
     * HTTP method, honouring a `_method` override (PUT/PATCH/DELETE) on POST
     * forms, since HTML forms only support GET and POST.
     */
    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? $this->header('X-HTTP-Method-Override') ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    /** Normalised path without query string, always starting with "/" and without trailing "/". */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Support installation in a sub-directory (e.g. /library/public/index.php).
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base !== '' && $base !== '.' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');
        return $path;
    }

    /** Raw request URI (path + query string) as sent by the client. */
    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->isJson()) {
            return $this->json()[$key] ?? $default;
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->isJson() ? $this->json() : array_merge($this->query, $this->post);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->jsonBody === null) {
            $raw = $this->rawBody ?? (string) file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            $this->jsonBody = is_array($decoded) ? $decoded : [];
        }
        return $this->jsonBody;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $value = $this->cookies[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        $plain = strtoupper(str_replace('-', '_', $name));
        if (in_array($plain, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) && isset($this->server[$plain])) {
            return (string) $this->server[$plain];
        }
        return null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type')), 'application/json');
    }

    /** True for API routes or clients that explicitly prefer JSON. */
    public function wantsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains(strtolower((string) $this->header('Accept')), 'application/json');
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        // Only trust proxy headers when explicitly enabled (see config/security.php).
        if (Config::get('security.trust_proxy_headers', false)) {
            return strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
        }
        return false;
    }

    /**
     * Client IP. X-Forwarded-For is used only when the direct peer is a
     * configured trusted proxy, and then the right-most hop that is not itself
     * a trusted proxy wins (left-most entries are client-controlled).
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('security.trusted_proxies', []);
        if ($trusted === [] || !self::ipInList($remote, $trusted)) {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::ipInList($hop, $trusted)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** @param list<string> $list IPs or CIDR ranges (IPv4/IPv6) */
    public static function ipInList(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($list as $entry) {
            [$net, $bits] = array_pad(explode('/', (string) $entry, 2), 2, null);
            $netBin = @inet_pton((string) $net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($bin) * 8 : max(0, min(strlen($bin) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('User-Agent'), 0, 500);
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
