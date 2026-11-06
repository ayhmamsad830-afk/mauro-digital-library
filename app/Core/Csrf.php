<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection bound to the PHP session.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::put(self::KEY, $token);
        }
        return $token;
    }

    public static function validate(?string $candidate): bool
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || $token === '' || !is_string($candidate) || $candidate === '') {
            return false;
        }
        return hash_equals($token, $candidate);
    }
}
