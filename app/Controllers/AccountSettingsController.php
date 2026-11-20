<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Services\AdminPasswordAuth;
use App\Services\Auth;
use App\Services\Member\MemberLibrary;
use App\Services\MemberPasswordAuth;
use App\Services\MemberPreferences;
use App\Services\RateLimiter;

/**
 * GET  /account/settings            profile, preferences, account information
 * POST /account/settings/profile    display name, language, theme (own account only — the
 *                                   id always comes from the session, never from the form)
 * POST /account/settings/password   change password (accounts that have one): current password
 *                                   required; other devices are signed out, this one stays
 */
final class AccountSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->page([]);
    }

    public function updateProfile(Request $request): Response
    {
        $uid = (int) Auth::id();
        $name = is_string($request->input('name')) ? trim((string) $request->input('name')) : '';
        $locale = (string) $request->input('preferred_locale', '');
        $theme = (string) $request->input('preferred_theme', '');
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > MemberPasswordAuth::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            $errors['name'] = 'member_auth.name_invalid';
        }
        if (!Translator::isSupported($locale)) {
            $errors['preferred_locale'] = 'validation.invalid';
        }
        if (!in_array($theme, MemberPreferences::THEMES, true)) {
            $errors['preferred_theme'] = 'validation.invalid';
        }
        if ($errors !== []) {
            return $this->page(['profileErrors' => $errors, 'old' => compact('name', 'locale', 'theme')], 422);
        }
        Database::query('UPDATE users SET name = :n, preferred_locale = :l, preferred_theme = :t, updated_at = :u WHERE id = :id',
            ['n' => $name, 'l' => $locale, 't' => $theme, 'u' => gmdate('Y-m-d H:i:s'), 'id' => $uid]);
        Session::flash('notice', __('settings.saved'));
        return MemberPreferences::apply($this->redirect(route('account.settings'), 303), $request, $locale, $theme);
    }

    public function updatePassword(Request $request): Response
    {
        $uid = (int) Auth::id();
        $row = Database::first('SELECT email, password_hash, role FROM users WHERE id = :id', ['id' => $uid]);
        $hash = is_string($row['password_hash'] ?? null) && $row['password_hash'] !== '' ? $row['password_hash'] : null;
        if ($hash === null || ($row['role'] ?? '') !== 'user') {
            return $this->page(['passwordError' => __('settings.no_password')], 422);
        }
        if (!RateLimiter::attempt('member_password_change', 'u:' . $uid)) {
            return $this->page(['passwordError' => __('member_auth.too_many')], 429);
        }
        $str = static fn (string $k) => is_string($request->input($k)) ? (string) $request->input($k) : '';
        $current = $str('current_password');
        $new = $str('new_password');
        if (!password_verify($current, $hash)) {
            return $this->page(['passwordError' => __('settings.current_wrong')], 422);
        }
        if (($err = MemberPasswordAuth::passwordError($new, (string) $row['email'])) !== null) {
            return $this->page(['passwordError' => __($err, ['min' => MemberPasswordAuth::MIN_LENGTH])], 422);
        }
        if (!hash_equals($new, $str('new_password_confirmation'))) {
            return $this->page(['passwordError' => __('member_auth.password_mismatch')], 422);
        }
        if (password_verify($new, $hash)) {
            return $this->page(['passwordError' => __('admin_auth.same_as_old')], 422);
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::query('UPDATE users SET password_hash = :h, password_changed_at = :c, updated_at = :u WHERE id = :id',
            ['h' => AdminPasswordAuth::hash($new), 'c' => $now, 'u' => $now, 'id' => $uid]);
        Database::query('DELETE FROM password_resets WHERE user_id = :u', ['u' => $uid]);
        Auth::passwordChangedHere(); // this device stays signed in; every other session ends
        Session::flash('notice', __('settings.password_changed'));
        return $this->redirect(route('account.settings'), 303);
    }

    private function page(array $data, int $status = 200): Response
    {
        $user = Auth::user();
        $uid = (int) $user['id'];
        $acct = Database::first('SELECT google_sub, password_hash, preferred_locale, preferred_theme, created_at, last_login_at FROM users WHERE id = :id', ['id' => $uid]);
        $hasPassword = is_string($acct['password_hash'] ?? null) && $acct['password_hash'] !== '';
        $google = is_string($acct['google_sub'] ?? null) && ctype_digit((string) $acct['google_sub']);
        return $this->view('account/settings', $data + [
            'pageTitle' => __('settings.title'),
            'noindex' => true,
            'section' => 'settings',
            'user' => $user + ['created_at' => (string) ($acct['created_at'] ?? '')],
            'account' => [
                'locale' => (string) ($acct['preferred_locale'] ?? locale()),
                'theme' => in_array($acct['preferred_theme'] ?? null, MemberPreferences::THEMES, true) ? (string) $acct['preferred_theme'] : 'dark',
                'last_login_at' => $acct['last_login_at'] ?? null,
                'google' => $google,
                'password' => $hasPassword,
            ],
            'favoritesCount' => count(MemberLibrary::favorites($uid)),
            'bookmarksCount' => count(MemberLibrary::bookmarks($uid)),
            'profileErrors' => [],
            'passwordError' => null,
            'old' => null,
        ], $status);
    }
}
