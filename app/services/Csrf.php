<?php

namespace App\services;

/**
 * Session-based CSRF protection.
 * Token is generated once per session and accepted either from a
 * `csrf_token` POST field or an `X-CSRF-Token` request header (for fetch).
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_token';
    private const FIELD_NAME  = 'csrf_token';
    private const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * Abort with 403 if the current POST request lacks a valid token.
     * No-op for non-POST methods.
     */
    public static function enforce(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            return;
        }

        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        $submitted = (string)(
            $_POST[self::FIELD_NAME]
            ?? $_SERVER[self::HEADER_NAME]
            ?? ''
        );

        if ($expected === '' || !hash_equals($expected, $submitted)) {
            \Flight::halt(403, 'Invalid CSRF token');
        }
    }
}
