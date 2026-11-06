<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private string $regex;

    /** @var list<string> */
    private array $paramNames = [];

    /** @param list<string> $methods */
    public function __construct(
        private readonly array $methods,
        private readonly string $path,
        private readonly mixed $handler,
        private readonly Router $router,
    ) {
        $this->regex = $this->compile($path);
    }

    private function compile(string $path): string
    {
        $regex = '';
        $offset = 0;
        // Matches {name} or {name:pattern}; pattern may contain one level of braces, e.g. {page:\d{1,4}}.
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', $path, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $match) {
                [$whole, $pos] = $match[0];
                $regex .= preg_quote(substr($path, $offset, $pos - $offset), '#');
                $name = $match[1][0];
                $pattern = $match[2][0] ?? '[^/]+';
                $this->paramNames[] = $name;
                $regex .= '(?P<' . $name . '>' . $pattern . ')';
                $offset = $pos + strlen($whole);
            }
        }
        $regex .= preg_quote(substr($path, $offset), '#');
        return '#^' . $regex . '$#u';
    }

    /** @return array<string,string>|null */
    public function matches(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $m[$name];
        }
        return $params;
    }

    public function build(array $params): string
    {
        $path = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(?:[^{}]|\{[^{}]*\})+)?\}/',
            function (array $m) use ($params): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new InvalidArgumentException("Missing route parameter [{$m[1]}].");
                }
                return rawurlencode((string) $params[$m[1]]);
            },
            $this->path
        );
        return (string) $path;
    }

    public function name(string $name): self
    {
        $this->router->registerName($name, $this);
        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $m) {
            if (!in_array($m, $this->middleware, true)) {
                $this->middleware[] = $m;
            }
        }
        return $this;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    /** @return list<string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }
}
