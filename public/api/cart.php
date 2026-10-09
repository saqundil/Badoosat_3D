<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\CartService;
use App\Service\ValidationException;
use App\Support\Csrf;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

/*
 * The JSON face of the cart. cart.php renders the same operations as HTML; both
 * call CartService and neither knows anything the other does not. A mobile
 * client would come here.
 */

$service = CartService::make();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cartId = Shop::cartIdIfAny();

    Response::json([
        'cart'  => $cartId === 0
            ? ['items' => [], 'subtotal_cents' => 0, 'total_cents' => 0, 'pieces' => 0]
            : $service->summary($cartId),
        'token' => Csrf::token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('GET or POST only.', 405);
}

$body = Response::jsonBody() ?: $_POST;
Csrf::require($body);

// A write creates the cart if this is the visitor's first line; a read never does.
$cart = Shop::cart();

try {
    match ((string) ($body['action'] ?? '')) {
        'add'      => $service->add($cart['id'], $body),
        'quantity' => $service->setQuantity($cart['id'], (int) ($body['item_id'] ?? 0), (int) ($body['quantity'] ?? 0)),
        'remove'   => $service->remove($cart['id'], (int) ($body['item_id'] ?? 0)),
        'clear'    => $service->clear($cart['id']),
        default    => Response::error('Unknown action.', 400),
    };
} catch (ValidationException $e) {
    Response::error(implode(' ', $e->errors()), 422, ['errors' => $e->errors()]);
}

Response::json(['cart' => $service->summary($cart['id'])]);
