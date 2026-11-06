<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;

/**
 * Small regex router.
 *
 *   $router->get('/books/{slug}', [BookController::class, 'show'])->name('books.show');
 *   $router->get('/api/books/{id:\d+}/pages/{page:\d+}', ...);
 *   $router->group(['prefix' => '/admin', 'middleware' => [AdminMiddleware::class]], function ($r) { ... });
 *
 * Route parameters default to the segment pattern [^/]+ and can be
 * constrained with {name:regex}.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var list<array{prefix:string, middleware:list<string>}> */
    private array $groupStack = [];

    public function get(string $path, callable|array $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $path, $handler);
    }

    public function post(string $path, callable|array $handler): Route
    {
        return $this->add(['POST'], $path, $handler);
    }

    public function put(string $path, callable|array $handler): Route
    {
        return $this->add(['PUT'], $path, $handler);
    }

    public function patch(string $path, callable|array $handler): Route
    {
        return $this->add(['PATCH'], $path, $handler);
    }

    public function delete(string $path, callable|array $handler): Route
    {
        return $this->add(['DELETE'], $path, $handler);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $path, callable|array $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }

        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $route = new Route($methods, $full, $handler, $this);
        $route->middleware(...$middleware);
        $this->routes[] = $route;
        return $route;
    }

    /** @param array{prefix?:string, middleware?:list<string>} $attributes */
    public function group(array $attributes, Closure $callback): void
    {
        $this->groupStack[] = [
            'prefix' => isset($attributes['prefix']) ? '/' . trim($attributes['prefix'], '/') : '',
            'middleware' => $attributes['middleware'] ?? [],
        ];
        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /** @internal called by Route::name() */
    public function registerName(string $name, Route $route): void
    {
        if (isset($this->named[$name])) {
            throw new InvalidArgumentException("Duplicate route name [$name].");
        }
        $this->named[$name] = $route;
    }

    /**
     * Find the route for a method + path.
     *
     * @return array{0: Route, 1: array<string, string>}
     * @throws HttpException 404 or 405
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $route->matches($path);
            if ($params === null) {
                continue;
            }
            if (in_array($method, $route->methods(), true)) {
                return [$route, $params];
            }
            $allowed = array_merge($allowed, $route->methods());
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowed)));
        }
        throw HttpException::notFound();
    }

    /** Build a URL path for a named route. */
    public function url(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException("Unknown route [$name].");
        }
        $path = $this->named[$name]->build($params);
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        return $path;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
