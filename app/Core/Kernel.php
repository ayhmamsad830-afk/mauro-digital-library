<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\CsrfMiddleware;
use App\Middleware\Middleware;
use App\Middleware\SecurityHeadersMiddleware;
use RuntimeException;
use Throwable;

/**
 * HTTP kernel: session → global middleware → router → route middleware →
 * controller. Any exception is converted to a safe response; security headers
 * are applied to error responses too.
 */
final class Kernel
{
    /** Global middleware, outermost first. */
    private const GLOBAL_MIDDLEWARE = [
        SecurityHeadersMiddleware::class,
        CsrfMiddleware::class,
    ];

    private static ?Router $router = null;

    public static function router(): Router
    {
        if (self::$router === null) {
            $router = new Router();
            $base = dirname(__DIR__, 2) . '/routes';
            foreach (['web.php', 'api.php', 'admin.php'] as $file) {
                if (is_file("$base/$file")) {
                    (static function (Router $router, string $file): void {
                        require $file;
                    })($router, "$base/$file");
                }
            }
            self::$router = $router;
        }
        return self::$router;
    }

    /** Tests: rebuild routes after changing configuration that affects registration (e.g. ui.demo). */
    public static function resetRouter(): void
    {
        self::$router = null;
    }

    public function handle(Request $request): Response
    {
        Session::start($request);
        \App\Services\Auth::reset();
        \App\Models\Setting::flush();
        Translator::resolve($request);

        // Request-derived values every layout needs; views never read superglobals.
        $theme = $request->cookie('dl_theme');
        View::share('currentTheme', in_array($theme, ['light', 'dark'], true) ? $theme : null);
        View::share('currentPath', $request->path());
        View::share('currentUri', safe_redirect_path($request->uri()));
        View::share('demoMode', (bool) Config::get('ui.demo', false));

        $core = function (Request $request): Response {
            [$route, $params] = self::router()->match($request->method(), $request->path());
            $request->setRouteParams($params);
            return $this->pipeline($route->middlewareList(), fn (Request $r) => $this->invoke($route, $r))($request);
        };

        try {
            $response = $this->pipeline(self::GLOBAL_MIDDLEWARE, function (Request $request) use ($core): Response {
                try {
                    return $core($request);
                } catch (Throwable $e) {
                    // Rendered inside the pipeline so headers middleware still applies.
                    return ErrorHandler::render($e, $request);
                }
            })($request);
        } catch (Throwable $e) {
            // Thrown by global middleware itself (e.g. CSRF failure).
            $response = (new SecurityHeadersMiddleware())->handle($request, fn () => ErrorHandler::render($e, $request));
        }

        if ($request->method() === 'HEAD') {
            $response = new Response('', $response->status(), $response->headers());
        }
        return $response;
    }

    /**
     * @param list<string> $middleware
     * @param callable(Request): Response $destination
     * @return callable(Request): Response
     */
    private function pipeline(array $middleware, callable $destination): callable
    {
        $next = $destination;
        foreach (array_reverse($middleware) as $class) {
            $next = static function (Request $request) use ($class, $next): Response {
                $instance = new $class();
                if (!$instance instanceof Middleware) {
                    throw new RuntimeException("$class is not a middleware.");
                }
                return $instance->handle($request, $next);
            };
        }
        return $next;
    }

    private function invoke(Route $route, Request $request): Response
    {
        $handler = $route->handler();
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->$method($request);
        } elseif (is_callable($handler)) {
            $result = $handler($request);
        } else {
            throw new RuntimeException('Invalid route handler for ' . $route->path());
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
