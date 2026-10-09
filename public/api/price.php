<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\DesignRepository;
use App\Repository\ProductRepository;
use App\Service\PricingService;
use App\Support\Csrf;
use App\Support\Response;

Csrf::session();

/*
 * What one configuration of a product costs.
 *
 * The product page changes its price when a colour, a size, a design or a
 * quantity changes -- and it changes it by asking here, not by multiplying in
 * JavaScript. That is the difference between a displayed price that can drift
 * from the charged one and a displayed price that is the charged one: the
 * arithmetic below is the same PricingService the cart and the order writer
 * call, over rows read from the same tables.
 *
 * Nothing here trusts a number in the query string except the quantity, and
 * that only decides which discount tier applies -- the tier itself comes from
 * config/shop.php.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('GET only.', 405);
}

$products = ProductRepository::make();

$variant = $products->findVariantBy(
    (int) ($_GET['product_id'] ?? 0),
    (int) ($_GET['color_id'] ?? 0),
    (int) ($_GET['size_id'] ?? 0)
);

if ($variant === null || !(int) $variant['is_active'] || !(int) $variant['product_active']) {
    Response::error('هذا اللون والمقاس غير متاحين.', 404);
}

$type      = (string) ($_GET['item_type'] ?? 'blank');
$placement = (string) ($_GET['placement'] ?? 'front');
$quantity  = max(1, min(200, (int) ($_GET['quantity'] ?? 1)));

if (!in_array($type, ['blank', 'ready', 'custom'], true)) {
    Response::error('نوع غير معروف.', 400);
}

if (!in_array($placement, ['front', 'back', 'both'], true)) {
    $placement = 'front';
}

// The surcharge is read off the design row, never off the request.
$surcharge = 0;

if ($type === 'ready') {
    $design = DesignRepository::make()->find((int) ($_GET['design_id'] ?? 0));

    if ($design === null || !(int) $design['is_active']
        || !DesignRepository::make()->isAllowedOn((int) $design['id'], (int) $variant['product_id'])) {
        Response::error('هذا التصميم غير متاح على هذا المنتج.', 404);
    }

    $surcharge = (int) $design['surcharge_cents'];
}

$line = PricingService::line(
    (int) $variant['price_cents'],
    $type,
    $surcharge,
    $placement,
    $quantity
);

Response::json([
    'variant_id'  => (int) $variant['id'],
    'sku'         => $variant['sku'],
    'stock'       => (int) $variant['stock'],
    'in_stock'    => (int) $variant['stock'] > 0,
    'quantity'    => $quantity,
    ...$line,
    'unit_label'  => money($line['unit_cents']),
    'total_label' => money($line['total_cents']),
    'saved_label' => $line['discount_cents'] > 0 ? money($line['discount_cents']) : null,
]);
