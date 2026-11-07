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
use App\Services\RateLimiter;

/**
 * Google Identity Services sign-in (spec §8).
 *
 *  GET  /login              official GIS button (popup mode) with a per-session nonce
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
        $configured = (string) Config::get('google.client_id', '') !== '';
        return $this->view('auth/login', [
            'pageTitle' => __('auth.title'),
            'noindex' => true,
            'googleConfigured' => $configured,
            'googleClientId' => (string) Config::get('google.client_id', ''),
            'googleNonce' => $configured ? GoogleAuthService::nonce() : '',
            'redirectTo' => $redirect,
            'error' => Session::getFlash('auth_error'),
            'scripts' => $configured ? ['auth.js'] : [],
        ]);
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
        Session::flash('notice', __('auth.signed_out'));
        return $this->redirect('/', 303);
    }
}
