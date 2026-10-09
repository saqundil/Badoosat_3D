<?php

declare(strict_types=1);

namespace App\Support;

use App\Service\CartService;

/**
 * The request-shaped glue between a browser and the cart.
 *
 * Cookies and sessions live here rather than in a service, for the same reason
 * nothing under src/Service reads a superglobal: a service that knows about
 * $_COOKIE cannot be called from the queue, from the command line or from a
 * test without inventing a request first. Everything below turns request state
 * into an integer -- a cart id, a customer id -- and hands that down.
 */
final class Shop
{
    /**
     * The cart for this visitor, made if this is their first click.
     *
     * @return array{id:int,token:string}
     */
    public static function cart(): array
    {
        $service = CartService::make();
        $cookie  = (string) Config::get('shop.cart.cookie', 'cart_ref');
        $token   = (string) ($_COOKIE[$cookie] ?? '');

        $existing = $token === '' ? null : $service->findByToken($token);

        if ($existing !== null) {
            $cart = ['id' => (int) $existing['id'], 'token' => (string) $existing['token']];

            // An account holder browsing on a device that still has a guest
            // cookie: bring the two together rather than showing them one and
            // charging them for the other.
            $customerId = self::customerId();

            if ($customerId !== null && $existing['customer_id'] === null) {
                $cart = $service->attachToCustomer($existing, $customerId);
                self::remember($cart['token']);
            }

            return $cart;
        }

        $cart = $service->createFor(self::customerId());
        self::remember($cart['token']);

        return $cart;
    }

    /** The cart id without creating one; zero when this visitor has none yet. */
    public static function cartIdIfAny(): int
    {
        $cookie = (string) Config::get('shop.cart.cookie', 'cart_ref');
        $token  = (string) ($_COOKIE[$cookie] ?? '');

        if ($token === '') {
            return 0;
        }

        $cart = CartService::make()->findByToken($token);

        return $cart === null ? 0 : (int) $cart['id'];
    }

    /** Badge count for the header. Never creates a cart: a visitor reading the
     *  about page should not be given one, and it costs a row and a cookie. */
    public static function cartCount(): int
    {
        $cartId = self::cartIdIfAny();

        return $cartId === 0 ? 0 : CartService::make()->count($cartId);
    }

    public static function remember(string $token): void
    {
        $config = Config::get('shop.cart');

        setcookie((string) $config['cookie'], $token, [
            'expires'  => time() + (int) $config['cookie_days'] * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);

        $_COOKIE[(string) $config['cookie']] = $token;
    }

    /* ------------------------------------------------------- the customer */

    public static function customerId(): ?int
    {
        Csrf::session();

        return isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;
    }

    public static function customer(): ?array
    {
        $id = self::customerId();

        return $id === null ? null : \App\Service\CustomerService::make()->find($id);
    }

    /** Signs a customer in and brings whatever they had in a guest cart with them. */
    public static function signIn(array $customer): void
    {
        Csrf::session();
        session_regenerate_id(true);

        $_SESSION['customer_id'] = (int) $customer['id'];
        Csrf::rotate();

        $cookie = (string) Config::get('shop.cart.cookie', 'cart_ref');
        $token  = (string) ($_COOKIE[$cookie] ?? '');
        $guest  = $token === '' ? null : CartService::make()->findByToken($token);

        $cart = CartService::make()->attachToCustomer($guest, (int) $customer['id']);
        self::remember($cart['token']);
    }

    public static function signOut(): void
    {
        Csrf::session();
        unset($_SESSION['customer_id']);
        session_regenerate_id(true);

        // The cart cookie is left alone. Signing out of a shared machine should
        // not silently delete a cart that belongs to the account, and the token
        // now points at a cart the account owns -- so clear the pointer, not
        // the cart.
        setcookie((string) Config::get('shop.cart.cookie', 'cart_ref'), '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[(string) Config::get('shop.cart.cookie', 'cart_ref')]);
    }
}
