<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\CustomerService;
use App\Service\OrderService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

$customerId = Shop::customerId();

if ($customerId === null) {
    Response::redirect('/account/login.php');
}

$service  = CustomerService::make();
$customer = $service->find($customerId);

if ($customer === null) {
    Shop::signOut();
    Response::redirect('/account/login.php');
}

$orders = $service->orders($customerId);

$title     = 'حسابي — ' . Config::get('app.name');
$current   = '';
$cartCount = Shop::cartCount();

$body = static function () use ($customer, $orders): void {
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <div>
                <h1 class="page__title">حسابي</h1>
                <p class="page__lede"><?= e($customer['name']) ?> · <?= e($customer['email']) ?></p>
            </div>
            <form method="post" action="/account/logout.php">
                <?= Csrf::field() ?>
                <button type="submit" class="button">خروج</button>
            </form>
        </div>

        <h2 class="specs__title">طلباتك</h2>

        <?php if ($orders === []) { ?>
            <p class="empty">لا طلبات بعد. <a href="/catalog.php">تصفّح المنتجات</a>.</p>
        <?php } else { ?>
            <ul class="lines">
                <?php foreach ($orders as $order) { ?>
                    <li class="line">
                        <span class="line__body">
                            <a class="line__name"
                               href="/order.php?number=<?= e(rawurlencode((string) $order['order_number'])) ?>">
                                <?= e($order['order_number']) ?>
                            </a>
                            <span class="line__meta">
                                <?= e(date('Y-m-d', strtotime((string) $order['created_at']))) ?>
                            </span>
                        </span>
                        <span class="badge badge--<?= e((string) $order['status']) ?>">
                            <?= e(OrderService::label((string) $order['status'])) ?>
                        </span>
                        <span class="line__total"><?= e(money((int) $order['total_cents'])) ?></span>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/layout.php';
