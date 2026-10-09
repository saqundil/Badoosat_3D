<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\CartService;
use App\Service\ValidationException;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

$service = CartService::make();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    $cart = Shop::cart();

    try {
        match ((string) ($_POST['action'] ?? '')) {
            'add'      => $service->add($cart['id'], $_POST),
            'quantity' => $service->setQuantity($cart['id'], (int) ($_POST['item_id'] ?? 0), (int) ($_POST['quantity'] ?? 0)),
            'remove'   => $service->remove($cart['id'], (int) ($_POST['item_id'] ?? 0)),
            default    => null,
        };

        if (($_POST['action'] ?? '') === 'add') {
            $_SESSION['notice'] = 'أُضيفت القطعة إلى السلة.';
        }
    } catch (ValidationException $e) {
        $_SESSION['errors'] = $e->errors();

        // Back to the page the customer was on, so they can fix it in context.
        if (($_POST['action'] ?? '') === 'add' && isset($_POST['product_id'])) {
            $slug = (string) ($_POST['slug'] ?? '');
            Response::redirect($slug === '' ? '/cart.php' : '/product.php?slug=' . rawurlencode($slug));
        }
    }

    Response::redirect('/cart.php');
}

$cartId  = Shop::cartIdIfAny();
$summary = $cartId === 0
    ? ['items' => [], 'dropped' => [], 'subtotal_cents' => 0, 'discount_cents' => 0,
       'shipping_cents' => 0, 'total_cents' => 0, 'pieces' => 0, 'shipping_gap_cents' => 0]
    : $service->summary($cartId);

$errors = $_SESSION['errors'] ?? [];
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['errors'], $_SESSION['notice']);

$title     = 'السلة — ' . Config::get('app.name');
$current   = '';
$cartCount = $summary['pieces'];

$body = static function () use ($summary, $errors, $notice): void {
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <h1 class="page__title">السلة</h1>
        </div>

        <?php if ($notice !== null) { ?>
            <p class="alert alert--ok"><?= e($notice) ?></p>
        <?php } ?>

        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <?php /* A line whose product or design was withdrawn while the cart sat
                 idle is removed and said so, rather than priced at whatever it
                 costs today or silently vanishing. */ ?>
        <?php if ($summary['dropped'] !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($summary['dropped'] as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <?php if ($summary['items'] === []) { ?>
            <p class="empty">
                سلتك فارغة. <a href="/catalog.php">تصفّح المنتجات</a>
                أو <a href="/upload.php">ارفع تصميمك</a>.
            </p>
        <?php } else { ?>
            <ul class="lines">
                <?php foreach ($summary['items'] as $line) { ?>
                    <li class="line">
                        <span class="line__media">
                            <?php if ($line['preview_path']) { ?>
                                <img src="<?= e($line['preview_path']) ?>" alt=""
                                     width="88" height="88" loading="lazy">
                            <?php } ?>
                        </span>

                        <span class="line__body">
                            <a class="line__name" href="/product.php?slug=<?= e(rawurlencode($line['product_slug'])) ?>">
                                <?= e($line['product_name']) ?>
                            </a>

                            <span class="line__meta">
                                <span class="line__swatch" style="background:<?= e($line['color_hex']) ?>"></span>
                                <?= e($line['color_name']) ?> · <?= e($line['size_code']) ?>
                                <?php if ($line['item_type'] === 'ready') { ?>
                                    · تصميم «<?= e($line['design_name']) ?>»
                                <?php } elseif ($line['item_type'] === 'custom') { ?>
                                    · تصميمك<?= $line['custom_source'] === 'upload' ? ' (ملف مرفوع)' : '' ?>
                                <?php } ?>
                                <?php if ($line['item_type'] !== 'blank') { ?>
                                    · <?= e(match ($line['placement']) {
                                        'back'  => 'الظهر',
                                        'both'  => 'الوجهان',
                                        default => 'الصدر',
                                    }) ?>
                                <?php } ?>
                            </span>

                            <span class="line__unit">
                                <?= e(money($line['unit_cents'])) ?> للقطعة
                                <?php if ($line['discount_percent'] > 0) { ?>
                                    <span class="line__discount">
                                        خصم <?= (int) $line['discount_percent'] ?>٪
                                        (−<?= e(money($line['discount_cents'])) ?>)
                                    </span>
                                <?php } ?>
                            </span>
                        </span>

                        <form class="line__qty" method="post" action="/cart.php">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="quantity">
                            <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">
                            <label class="visually-hidden" for="q-<?= (int) $line['id'] ?>">الكمية</label>
                            <input id="q-<?= (int) $line['id'] ?>" type="number" name="quantity"
                                   value="<?= (int) $line['quantity'] ?>" min="0"
                                   max="<?= (int) $line['stock'] ?>" onchange="this.form.submit()">
                        </form>

                        <span class="line__total"><?= e(money($line['total_cents'])) ?></span>

                        <form method="post" action="/cart.php">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="item_id" value="<?= (int) $line['id'] ?>">
                            <button type="submit" class="linky linky--danger">حذف</button>
                        </form>
                    </li>
                <?php } ?>
            </ul>

            <div class="totals">
                <div class="totals__row">
                    <span>المجموع (<?= (int) $summary['pieces'] ?> قطعة)</span>
                    <span><?= e(money($summary['subtotal_cents'])) ?></span>
                </div>

                <?php if ($summary['discount_cents'] > 0) { ?>
                    <div class="totals__row totals__row--good">
                        <span>خصم الكمية</span>
                        <span>−<?= e(money($summary['discount_cents'])) ?></span>
                    </div>
                <?php } ?>

                <div class="totals__row">
                    <span>الشحن</span>
                    <span>
                        <?= $summary['shipping_cents'] === 0
                            ? 'مجاني'
                            : e(money($summary['shipping_cents'])) ?>
                    </span>
                </div>

                <?php if ($summary['shipping_gap_cents'] > 0) { ?>
                    <p class="totals__hint">
                        أضف <?= e(money($summary['shipping_gap_cents'])) ?> ليصبح الشحن مجانيًا.
                    </p>
                <?php } ?>

                <div class="totals__row totals__row--grand">
                    <span>الإجمالي</span>
                    <span><?= e(money($summary['total_cents'])) ?></span>
                </div>

                <a class="button button--primary button--block" href="/checkout.php">إتمام الطلب</a>
                <p class="hint hint--tight" style="margin-top:10px">
                    تقدر تتم الطلب كضيف، بلا إنشاء حساب.
                </p>
            </div>
        <?php } ?>
    </main>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
