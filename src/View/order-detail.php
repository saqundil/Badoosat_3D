<?php

declare(strict_types=1);

/**
 * One order, as the customer sees it. Shared by the confirmation page and the
 * tracking page so there is one description of an order on the customer side,
 * not two that drift.
 *
 * @var array $order hydrated by OrderService
 */

$steps = ['pending', 'paid', 'in_production', 'shipped', 'delivered'];
$at    = array_search((string) $order['status'], $steps, true);
?>
<main class="page page--narrow">
    <div class="page__head">
        <div>
            <h1 class="page__title">الطلب <?= e($order['order_number']) ?></h1>
            <p class="page__lede">
                باسم <?= e($order['customer_name']) ?> ·
                <?= e(date('Y-m-d', strtotime((string) $order['created_at']))) ?>
            </p>
        </div>
        <p class="badge badge--<?= e((string) $order['status']) ?>"><?= e($order['status_label']) ?></p>
    </div>

    <?php if ($at !== false) { ?>
        <?php /* Cancelled and refunded orders have no place on this line, so
                 they get the badge above and no track at all. */ ?>
        <ol class="track">
            <?php foreach ($steps as $i => $step) { ?>
                <li class="track__step<?= $i <= $at ? ' is-done' : '' ?>">
                    <span class="track__dot" aria-hidden="true"></span>
                    <span class="track__label"><?= e(App\Service\OrderService::label($step)) ?></span>
                </li>
            <?php } ?>
        </ol>
    <?php } ?>

    <ul class="lines">
        <?php foreach ($order['items'] as $item) { ?>
            <li class="line">
                <span class="line__media">
                    <?php
                    $shot = $item['item_type'] === 'custom'
                        ? $item['custom_preview']
                        : ($item['item_type'] === 'ready' ? $item['design_thumb'] : null);
                    ?>
                    <?php if ($shot) { ?>
                        <img src="<?= e($shot) ?>" alt="" width="88" height="88" loading="lazy">
                    <?php } ?>
                </span>

                <span class="line__body">
                    <span class="line__name"><?= e($item['product_name']) ?></span>
                    <span class="line__meta">
                        <span class="line__swatch" style="background:<?= e($item['color_hex']) ?>"></span>
                        <?= e($item['color_name']) ?> · <?= e($item['size_code']) ?>
                        <?php if ($item['item_type'] === 'ready') { ?>
                            · تصميم «<?= e($item['design_name']) ?>»
                        <?php } elseif ($item['item_type'] === 'custom') { ?>
                            · تصميمك
                        <?php } ?>
                    </span>
                </span>

                <span class="line__qty-static"><?= (int) $item['quantity'] ?> ×</span>
                <span class="line__total"><?= e(money((int) $item['line_total_cents'])) ?></span>
            </li>
        <?php } ?>
    </ul>

    <div class="totals">
        <div class="totals__row">
            <span>المجموع</span>
            <span><?= e(money((int) $order['subtotal_cents'])) ?></span>
        </div>

        <?php if ((int) $order['discount_cents'] > 0) { ?>
            <div class="totals__row totals__row--good">
                <span>خصم الكمية</span>
                <span>−<?= e(money((int) $order['discount_cents'])) ?></span>
            </div>
        <?php } ?>

        <div class="totals__row">
            <span>الشحن</span>
            <span><?= (int) $order['shipping_cents'] === 0 ? 'مجاني' : e(money((int) $order['shipping_cents'])) ?></span>
        </div>

        <div class="totals__row totals__row--grand">
            <span>الإجمالي</span>
            <span><?= e(money((int) $order['total_cents'])) ?></span>
        </div>
    </div>

    <div class="specs">
        <h2 class="specs__title">التسليم</h2>
        <dl class="specs__list">
            <div><dt>العنوان</dt><dd><?= e($order['address']) ?>، <?= e($order['city']) ?></dd></div>
            <div><dt>الهاتف</dt><dd><?= e($order['phone']) ?></dd></div>
            <?php if ($order['note']) { ?>
                <div><dt>ملاحظتك</dt><dd><?= e($order['note']) ?></dd></div>
            <?php } ?>
        </dl>
    </div>

    <p class="hint">
        احتفظ برقم الطلب. تقدر تتبّعه في أي وقت من
        <a href="/track.php">صفحة التتبّع</a> برقمه ورقم هاتفك.
    </p>
</main>
