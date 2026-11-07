<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
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
        // Password-based admin sessions have an absolute lifetime (re-authenticate after it).
        if (Session::get('auth_method') === 'password') {
            $at = (int) Session::get('auth_at', 0);
            if ($at < 1 || time() - $at > (int) Config::get('security.admin_session_max', 28800)) {
                Auth::logout();
                Session::flash('admin_auth_error', __('admin_auth.expired'));
                return Response::redirect('/admin/login?' . http_build_query(['redirect' => safe_redirect_path($request->uri(), '/admin')]));
            }
        }
        // A temporary password must be replaced before anything else in the admin area.
        if ((int) (Auth::user()['password_must_change'] ?? 0) === 1 && $request->path() !== '/admin/password') {
            if ($request->wantsJson()) {
                throw new HttpException(403, __('admin_auth.must_change_intro'));
            }
            return Response::redirect('/admin/password');
        }
        return $next($request);
    }
}
