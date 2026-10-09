<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\CartService;
use App\Service\OrderService;
use App\Service\ValidationException;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Mail;
use App\Support\RateLimiter;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

$cartId = Shop::cartIdIfAny();

if ($cartId === 0) {
    Response::redirect('/cart.php');
}

$service  = CartService::make();
$orders   = OrderService::make();
$summary  = $service->summary($cartId);
$customer = Shop::customer();

if ($summary['items'] === []) {
    Response::redirect('/cart.php');
}

$errors = [];
$input  = [
    'customer_name' => $customer['name'] ?? '',
    'email'         => $customer['email'] ?? '',
    'phone'         => $customer['phone'] ?? '',
    'city'          => '',
    'address'       => '',
    'note'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);
    $input = [...$input, ...array_intersect_key($_POST, $input)];

    if (!RateLimiter::make()->check('checkout', RateLimiter::client())) {
        $errors['rate'] = 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.';
    } else {
        try {
            /*
             * Nothing from this form reaches a total. The order writer prices
             * the cart again from the catalogue and claims the stock under a
             * row lock; the figures rendered below are a display of what that
             * will produce, not an input to it.
             */
            $placed = $orders->place($cartId, $input, Shop::customerId());

            Mail::orderPlaced($placed['id']);

            // Authorises exactly one view of the confirmation page. Coming back
            // to an order later goes through /track.php, which asks for the
            // phone number as well as the order number.
            $_SESSION['placed'] = $placed['number'];

            Response::redirect('/order.php?number=' . rawurlencode($placed['number']));
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $summary = $service->summary($cartId);
        }
    }
}

$title     = 'إتمام الطلب — ' . Config::get('app.name');
$current   = '';
$cartCount = $summary['pieces'];

$body = static function () use ($summary, $errors, $input, $orders, $customer): void {
    $returns = (string) Config::get('shop.returns_notice');
    $custom  = array_filter($summary['items'], static fn (array $l): bool => $l['item_type'] !== 'blank');
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <h1 class="page__title">إتمام الطلب</h1>
        </div>

        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <?php if ($customer === null) { ?>
            <p class="hint">
                تقدر تتم الطلب كضيف بلا حساب.
                <a href="/account/login.php?next=/checkout.php">أو سجّل الدخول</a>
                لتحفظ عنوانك للمرات القادمة.
            </p>
        <?php } ?>

        <?php /*
            The agreement. Every printed line shows the picture that was saved
            with it -- the same image the production floor will work from -- so
            what the customer approves here and what comes off the press are the
            same thing, and there is a record of it that predates the argument.
        */ ?>
        <?php if ($custom !== []) { ?>
            <section class="agreement">
                <h2 class="agreement__title">ما سيُطبع</h2>
                <ul class="agreement__list">
                    <?php foreach ($custom as $line) { ?>
                        <li class="agreement__item">
                            <span class="agreement__shot">
                                <?php if ($line['preview_path']) { ?>
                                    <img src="<?= e($line['preview_path']) ?>" alt=""
                                         width="120" height="120" loading="lazy">
                                <?php } ?>
                            </span>
                            <span>
                                <strong><?= e($line['product_name']) ?></strong><br>
                                <?= e($line['color_name']) ?> · <?= e($line['size_code']) ?> ·
                                <?= (int) $line['quantity'] ?> قطعة ·
                                <?= e(match ($line['placement']) {
                                    'back'  => 'الظهر',
                                    'both'  => 'الوجهان',
                                    default => 'الصدر',
                                }) ?>
                            </span>
                        </li>
                    <?php } ?>
                </ul>
            </section>
        <?php } ?>

        <form class="checkout" method="post" action="/checkout.php">
            <?= Csrf::field() ?>

            <div class="checkout__fields">
                <label class="field">
                    <span class="field__label">الاسم</span>
                    <input type="text" name="customer_name" value="<?= e($input['customer_name']) ?>"
                           required maxlength="160" autocomplete="name">
                </label>

                <label class="field">
                    <span class="field__label">البريد الإلكتروني</span>
                    <input type="email" name="email" value="<?= e($input['email']) ?>"
                           required maxlength="190" autocomplete="email">
                </label>

                <label class="field">
                    <span class="field__label">رقم الهاتف</span>
                    <input type="tel" name="phone" value="<?= e($input['phone']) ?>"
                           required maxlength="40" autocomplete="tel">
                    <span class="hint hint--tight">نتتبّع الطلب برقم الهاتف مع رقم الطلب.</span>
                </label>

                <label class="field">
                    <span class="field__label">المدينة</span>
                    <input type="text" name="city" value="<?= e($input['city']) ?>"
                           required maxlength="80" autocomplete="address-level2">
                </label>

                <label class="field">
                    <span class="field__label">العنوان</span>
                    <textarea name="address" rows="3" required maxlength="500"
                              autocomplete="street-address"><?= e($input['address']) ?></textarea>
                </label>

                <label class="field">
                    <span class="field__label">ملاحظة <span class="upload__opt">اختيارية</span></span>
                    <textarea name="note" rows="2" maxlength="500"><?= e($input['note']) ?></textarea>
                </label>
            </div>

            <aside class="checkout__summary">
                <h2 class="specs__title">الملخّص</h2>

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
                    <span><?= $summary['shipping_cents'] === 0 ? 'مجاني' : e(money($summary['shipping_cents'])) ?></span>
                </div>

                <div class="totals__row totals__row--grand">
                    <span>الإجمالي</span>
                    <span><?= e(money($summary['total_cents'])) ?></span>
                </div>

                <p class="checkout__pay"><?= e($orders->driver()->label()) ?></p>

                <?php /*
                    Next to the button, not in the footer. This is the last
                    moment a customer can change their mind about a shirt that
                    cannot be resold to anybody else, and burying it where
                    nobody reads is how a refund argument starts.
                */ ?>
                <?php if ($custom !== []) { ?>
                    <p class="checkout__returns"><?= e($returns) ?></p>
                <?php } ?>

                <button type="submit" class="button button--primary button--block">أكّد الطلب</button>
            </aside>
        </form>
    </main>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
