<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use App\Services\Mail\Mailer;
use App\Services\PasswordResetService;

/**
 * Member password recovery.
 *
 *  GET  /password/forgot          request form (honest notice while e-mail delivery is disabled)
 *  POST /password/forgot          always the same answer (no account enumeration); CSRF + rate limits
 *  GET  /password/reset?token=…   checks the token, keeps it in the session and redirects to a clean
 *                                 URL so the token never stays in history or in a Referer header
 *  GET  /password/reset           new-password form (when a valid token is in the session)
 *  POST /password/reset           sets the password, signs every device out, redirects to /login
 */
final class PasswordController extends Controller
{
    public function showForgot(Request $request): Response
    {
        return $this->page('auth/forgot', ['available' => Mailer::canDeliver(), 'state' => null, 'email' => '']);
    }

    public function sendLink(Request $request): Response
    {
        $email = is_string($request->input('email')) ? mb_substr(trim((string) $request->input('email')), 0, 190) : '';
        $state = PasswordResetService::request($email, $request->ip());
        $response = $this->page('auth/forgot', ['available' => $state !== 'unavailable', 'state' => $state, 'email' => $state === 'accepted' ? '' : $email],
            $state === 'throttled' ? 429 : 200);
        if ($state === 'throttled') {
            $response->header('Retry-After', '3600');
        }
        return $response;
    }

    public function showReset(Request $request): Response
    {
        $token = $request->query('token');
        if (is_string($token) && $token !== '') {
            if (PasswordResetService::find($token) === null) {
                Session::forget('password_reset_token');
                return $this->page('auth/reset', ['valid' => false, 'error' => null], 410);
            }
            Session::put('password_reset_token', $token);
            return $this->redirect('/password/reset', 303);
        }
        $stored = (string) Session::get('password_reset_token', '');
        return $this->page('auth/reset', ['valid' => $stored !== '' && PasswordResetService::find($stored) !== null, 'error' => null]);
    }

    public function reset(Request $request): Response
    {
        $token = (string) Session::get('password_reset_token', '');
        $str = static fn (string $k) => is_string($request->input($k)) ? (string) $request->input($k) : '';
        $error = PasswordResetService::complete($token, $str('password'), $str('password_confirmation'), $request->ip());
        if ($error !== null) {
            $valid = $error !== 'reset.invalid_link';
            return $this->page('auth/reset', ['valid' => $valid, 'error' => __($error, ['min' => \App\Services\MemberPasswordAuth::MIN_LENGTH])], $valid ? 422 : 410);
        }
        Session::forget('password_reset_token');
        Auth::logout(); // this browser too: sign in again with the new password
        Session::flash('notice', __('reset.done'));
        return $this->redirect('/login', 303);
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        return $this->view($template, $data + [
            'pageTitle' => __($template === 'auth/forgot' ? 'reset.forgot_title' : 'reset.reset_title'),
            'noindex' => true,
        ], $status);
    }
}
