<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AdminPasswordAuth;
use App\Services\Auth;

/**
 *  GET  /admin/login      username + password form (public, noindex)
 *  POST /admin/login      verify → regenerate session → /admin (or forced password change)
 *  GET  /admin/password   change password (admin only; forced after a temporary password)
 *  POST /admin/password
 *
 * CSRF + Origin checks are global (CsrfMiddleware). Session cookies are HttpOnly, SameSite=Lax and
 * Secure on HTTPS (SESSION_SECURE_COOKIE=true in production).
 */
final class AdminAuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        $redirect = self::target((string) $request->query('redirect', '/admin'));
        if (Auth::isAdmin()) {
            return $this->redirect($redirect);
        }
        return $this->loginView($redirect, Session::getFlash('admin_auth_error'), '', 200);
    }

    public function login(Request $request): Response
    {
        $redirect = self::target((string) $request->input('redirect', '/admin'));
        $username = is_string($request->input('username')) ? (string) $request->input('username') : '';
        $password = is_string($request->input('password')) ? (string) $request->input('password') : '';

        $result = AdminPasswordAuth::attempt($username, $password, $request->ip());
        if (!$result['ok']) {
            $throttled = $result['reason'] === 'throttled';
            $response = $this->loginView(
                $redirect,
                __($throttled ? 'admin_auth.too_many' : 'admin_auth.invalid'),
                mb_substr(trim($username), 0, 64),
                $throttled ? 429 : 422
            );
            if ($throttled) {
                $response->header('Retry-After', (string) (int) Config::get('security.rate_limits.admin_login_user.window', 900));
            }
            return $response;
        }

        Auth::login((int) $result['user_id']);             // regenerates the session id
        Session::put('auth_method', 'password');
        Session::put('auth_at', time());
        if ($result['must_change']) {
            Session::flash('notice', __('admin_auth.must_change_intro'));
            return $this->redirect(route('admin.password'), 303);
        }
        return $this->redirect($redirect, 303);
    }

    public function showPassword(Request $request): Response
    {
        return $this->passwordView(null, 200);
    }

    public function changePassword(Request $request): Response
    {
        $str = static fn (string $k): string => is_string($request->input($k)) ? (string) $request->input($k) : '';
        $error = AdminPasswordAuth::changePassword((int) Auth::id(), $str('current_password'), $str('new_password'), $str('new_password_confirmation'), $request->ip());
        if ($error !== null) {
            return $this->passwordView(__($error, ['min' => AdminPasswordAuth::MIN_LENGTH]), 422);
        }
        Session::regenerate();
        Session::put('auth_at', time());
        Session::flash('success', __('admin_auth.changed'));
        return $this->redirect(route('admin.dashboard'), 303);
    }

    private function loginView(string $redirect, ?string $error, string $username, int $status): Response
    {
        return $this->view('admin/auth/login', [
            'pageTitle' => __('admin_auth.title'),
            'noindex' => true,
            'redirectTo' => $redirect,
            'error' => $error,
            'username' => $username,
            'googleLogin' => (string) Config::get('google.client_id', '') !== '',
        ], $status);
    }

    private function passwordView(?string $error, int $status): Response
    {
        $row = Database::first('SELECT username, password_hash, password_must_change FROM users WHERE id = :id', ['id' => Auth::id()]);
        return $this->view('admin/auth/password', [
            'pageTitle' => __('admin_auth.change_title'),
            'noindex' => true,
            'error' => $error,
            'forced' => (int) ($row['password_must_change'] ?? 0) === 1,
            'hasPassword' => is_string($row['password_hash'] ?? null) && $row['password_hash'] !== '',
            'username' => (string) ($row['username'] ?? ''),
        ], $status);
    }

    /** Only same-site admin paths are valid post-login targets. */
    private static function target(string $path): string
    {
        $path = safe_redirect_path($path, '/admin');
        return str_starts_with($path, '/admin') && !str_starts_with($path, '/admin/login') ? $path : '/admin';
    }
}
