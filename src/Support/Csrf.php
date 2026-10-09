<?php

declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public static function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);

        session_start();
    }

    public static function token(): string
    {
        self::session();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . Response::e(self::token()) . '">';
    }

    public static function valid(mixed $token): bool
    {
        self::session();

        return is_string($token)
            && isset($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    /** Rejects the request when the token is missing or wrong. */
    public static function require(?array $body = null): void
    {
        $token = $body['_token']
            ?? $_POST['_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? null;

        if (self::valid($token)) {
            return;
        }

        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            Response::error('Invalid CSRF token.', 419);
        }

        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'انتهت صلاحية النموذج. أعد تحميل الصفحة وحاول مرة أخرى.';
        exit;
    }

    public static function rotate(): void
    {
        self::session();
        unset($_SESSION['csrf']);
    }
}
