<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use App\Services\Google\GoogleAuthService;
use App\Services\MemberPasswordAuth;
use App\Services\RateLimiter;

/**
 * Reader sign-in: Google Identity Services (spec §8) or e-mail + password.
 *
 *  GET  /login              official GIS button (popup mode) with a per-session nonce + e-mail form
 *  POST /login/email        e-mail + password → session (CSRF + rate limits)
 *  GET  /register           free account: Google button or name / e-mail / password
 *  POST /register           create the account and sign in (CSRF + rate limit)
 *  POST /api/auth/google    ID token → server-side verification → session (CSRF + rate limit)
 *  POST /logout             end the session (CSRF)
 *
 * No client secret is used or stored: the ID-token flow needs only the public client ID.
 */
final class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        $redirect = safe_redirect_path((string) $request->query('redirect', '/account'), '/account');
        if (Auth::user() !== null) {
            return $this->redirect($redirect);
        }
        return $this->authView('auth/login', $redirect, ['error' => Session::getFlash('auth_error')]);
    }

    /** POST /login/email — e-mail + password sign-in for readers. */
    public function emailLogin(Request $request): Response
    {
        $redirect = safe_redirect_path((string) $request->input('redirect', '/account'), '/account');
        $email = is_string($request->input('email')) ? (string) $request->input('email') : '';
        $password = is_string($request->input('password')) ? (string) $request->input('password') : '';

        $result = MemberPasswordAuth::attempt($email, $password, $request->ip());
        if (!$result['ok']) {
            $key = match ($result['reason']) {
                'throttled' => 'member_auth.too_many',
                'suspended' => 'auth.suspended',
                default => 'member_auth.invalid',
            };
            $response = $this->authView('auth/login', $redirect, [
                'error' => __($key), 'email' => mb_substr(trim($email), 0, MemberPasswordAuth::EMAIL_MAX),
            ], $result['reason'] === 'throttled' ? 429 : 422);
            if ($result['reason'] === 'throttled') {
                $response->header('Retry-After', (string) (int) Config::get('security.rate_limits.member_login_email.window', 900));
            }
            return $response;
        }
        Auth::login((int) $result['user_id']); // regenerates the session id
        Session::put('auth_method', 'email');
        return $this->redirect($redirect, 303);
    }

    /** GET /register — free account with e-mail + password (or Google). */
    public function showRegister(Request $request): Response
    {
        $redirect = safe_redirect_path((string) $request->query('redirect', '/account'), '/account');
        if (Auth::user() !== null) {
            return $this->redirect($redirect);
        }
        return $this->authView('auth/register', $redirect);
    }

    /** POST /register */
    public function register(Request $request): Response
    {
        $redirect = safe_redirect_path((string) $request->input('redirect', '/account'), '/account');
        $str = static fn (string $k): string => is_string($request->input($k)) ? (string) $request->input($k) : '';
        $result = MemberPasswordAuth::register($str('name'), $str('email'), $str('password'), $str('password_confirmation'), $request->ip());
        if (!$result['ok']) {
            $throttled = $result['reason'] === 'throttled';
            return $this->authView('auth/register', $redirect, [
                'error' => $throttled ? __('member_auth.too_many_register') : __('member_auth.fix_errors'),
                'fieldErrors' => array_map(static fn (string $k) => __($k, ['min' => MemberPasswordAuth::MIN_LENGTH]), $result['errors']),
                'emailTaken' => ($result['errors']['email'] ?? '') === 'member_auth.email_taken',
                'name' => mb_substr(trim($str('name')), 0, MemberPasswordAuth::NAME_MAX),
                'email' => mb_substr(trim($str('email')), 0, MemberPasswordAuth::EMAIL_MAX),
            ], $throttled ? 429 : 422);
        }
        Auth::login((int) $result['user_id']);
        Session::put('auth_method', 'email');
        Session::flash('notice', __('member_auth.welcome'));
        return $this->redirect($redirect, 303);
    }

    /** Sign-in and registration pages share the Google button and the e-mail form state. */
    private function authView(string $template, string $redirect, array $extra = [], int $status = 200): Response
    {
        $configured = (string) Config::get('google.client_id', '') !== '';
        return $this->view($template, $extra + [
            'pageTitle' => __($template === 'auth/register' ? 'member_auth.register_title' : 'auth.title'),
            'noindex' => true,
            'googleConfigured' => $configured,
            'googleClientId' => (string) Config::get('google.client_id', ''),
            'googleNonce' => $configured ? GoogleAuthService::nonce() : '',
            'redirectTo' => $redirect,
            'error' => null,
            'fieldErrors' => [],
            'name' => '',
            'email' => '',
            'scripts' => $configured ? ['auth.js'] : [],
        ], $status);
    }

    public function google(Request $request): Response
    {
        if ((string) Config::get('google.client_id', '') === '') {
            throw new HttpException(503, __('auth.not_configured'));
        }
        RateLimiter::enforce('google_signin', 'ip:' . $request->ip());
        $credential = $request->input('credential');
        if (!is_string($credential) || $credential === '') {
            throw new HttpException(422, __('auth.failed'));
        }
        $result = GoogleAuthService::signIn($credential, $request->ip());
        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'suspended' => __('auth.suspended'),
                'email_conflict' => __('auth.email_conflict'),
                'email_unverified' => __('auth.email_unverified'),
                'domain_not_allowed' => __('auth.domain_not_allowed'),
                default => __('auth.failed'),
            };
            return $this->json(['error' => ['status' => 401, 'message' => $message]], 401);
        }
        $redirect = safe_redirect_path((string) $request->input('redirect', '/account'), '/account');
        $response = $this->json(['ok' => true, 'redirect' => $redirect, 'csrf' => \App\Core\Csrf::token()]);
        // Apply the language the member chose on a previous visit/device.
        $pref = \App\Core\Database::scalar('SELECT preferred_locale FROM users WHERE id = :id', ['id' => $result['user_id']]);
        if (is_string($pref) && \App\Core\Translator::isSupported($pref)) {
            $response->cookie(\App\Core\Translator::COOKIE, $pref, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $request->isSecure(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        return $response;
    }

    public function logout(Request $request): Response
    {
        $wasAdmin = Auth::isAdmin();
        if ($wasAdmin) {
            \App\Models\ActivityLog::record((int) Auth::id(), 'admin.logout', 'user', (int) Auth::id(), (string) Session::get('auth_method', 'google'), $request->ip());
        }
        Auth::logout();
        // "Switch account": same full sign-out, then straight to the sign-in page
        // (fixed internal route, never a caller-supplied URL) to pick another account.
        if ((string) $request->input('switch', '') === '1') {
            Session::flash('notice', __('auth.switch_notice'));
            return $this->redirect(route('login'), 303);
        }
        Session::flash('notice', __('auth.signed_out'));
        return $this->redirect('/', 303);
    }
}
