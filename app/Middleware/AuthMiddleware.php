<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/** Requires an active signed-in member. Guests go to /login (or get 401 JSON on the API). */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::user() === null) {
            if ($request->wantsJson()) {
                throw new HttpException(401, __('errors.401.message'));
            }
            return Response::redirect('/login?' . http_build_query(['redirect' => safe_redirect_path($request->uri())]));
        }
        return $next($request);
    }
}
