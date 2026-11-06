<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/**
 * Server-side admin authorization for every /admin route (spec §4 rule:
 * hiding buttons is never enough). Must run after AuthMiddleware.
 */
final class AdminMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::isAdmin()) {
            throw HttpException::forbidden();
        }
        return $next($request);
    }
}
