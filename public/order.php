<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\OrderService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

/*
 * The page a customer lands on straight after paying.
 *
 * It shows the order once, by number alone, because the customer has just
 * placed it and is holding the number. It is not a way back in: a reload an
 * hour later, or the number pasted from a friend, gets nothing -- the session
 * flag that authorises this view is set by checkout and by nothing else.
 * Coming back later is what /track.php is for, and that asks for the phone.
 */
$number = (string) ($_GET['number'] ?? '');
$order  = OrderService::make()->byNumber($number);

$justPlaced = isset($_SESSION['placed']) && $_SESSION['placed'] === ($order['order_number'] ?? null);

if ($order === null || (!$justPlaced && (int) ($order['customer_id'] ?? 0) !== (int) (Shop::customerId() ?? -1))) {
    Response::redirect('/track.php?number=' . rawurlencode($number));
}

$title     = 'تم استلام طلبك — ' . Config::get('app.name');
$current   = '';
$cartCount = Shop::cartCount();

$body = static function () use ($order): void {
    require dirname(__DIR__) . '/src/View/order-detail.php';
};

require dirname(__DIR__) . '/src/View/layout.php';
