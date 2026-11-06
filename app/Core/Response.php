<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response value object. Nothing is sent until send() is called, which
 * lets middleware add headers (security headers, cache policy) afterwards.
 */
class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($body, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        // Prevent header injection and open redirects to other hosts.
        $url = str_replace(["\r", "\n"], '', $url);
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $appUrl = rtrim((string) Config::get('app.url', ''), '/');
            if ($appUrl === '' || (!str_starts_with($url, $appUrl . '/') && $url !== $appUrl)) {
                $url = '/';
            }
        }
        return new self('', $status, ['Location' => $url]);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$this->normalise($name)] = str_replace(["\r", "\n"], '', $value);
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$this->normalise($name)]);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->normalise($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string, mixed> $options */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By'); // do not advertise the PHP version (also set expose_php=Off)
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options'] + [
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        echo $this->body;
    }

    private function normalise(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
