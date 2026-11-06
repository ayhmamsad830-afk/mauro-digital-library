<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Translator;

final class PreferenceController extends Controller
{
    /** Persist the interface language for one year and return to the same page. */
    public function setLocale(Request $request): Response
    {
        $locale = (string) $request->input('locale', '');
        $back = safe_redirect_path((string) $request->input('redirect', '/'));

        if (!Translator::isSupported($locale)) {
            return $this->redirect($back);
        }

        if (($uid = \App\Services\Auth::id()) !== null) {
            \App\Core\Database::query('UPDATE users SET preferred_locale = :l WHERE id = :id', ['l' => $locale, 'id' => $uid]);
        }
        return $this->redirect($back, 303)->cookie(Translator::COOKIE, $locale, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
