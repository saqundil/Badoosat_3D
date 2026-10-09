<?php

declare(strict_types=1);

namespace App\Support;

final class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $admin = Config::file('app')['admin'];
        $hash  = (string) ($admin['password_hash'] ?? '');

        // Both factors are checked before answering, so a wrong username and a
        // wrong password cost the same.
        $userOk = hash_equals((string) $admin['username'], $username);
        $passOk = $hash !== '' && password_verify($password, $hash);

        if (!$userOk || !$passOk) {
            return false;
        }

        Csrf::session();
        session_regenerate_id(true);
        $_SESSION['admin'] = $username;
        Csrf::rotate();

        return true;
    }

    public static function check(): bool
    {
        Csrf::session();

        return !empty($_SESSION['admin']);
    }

    /**
     * Call before any output on every admin page.
     *
     * It also settles the panel's language (Lang::applyAdminChoice), because
     * every admin page already calls this first and the choice lives in the
     * session this has just checked. $adminLocale is false only for the slide
     * preview, whose ?lang= is a parameter of the frame, not the panel.
     */
    public static function require(bool $adminLocale = true): void
    {
        if (self::check()) {
            if ($adminLocale) {
                Lang::applyAdminChoice();
            }

            return;
        }

        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            Response::error('Not authenticated.', 401);
        }

        Response::redirect('/admin/login.php');
    }

    public static function logout(): void
    {
        Csrf::session();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }
}
