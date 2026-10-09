<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\OrderService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\RateLimiter;
use App\Support\Shop;

Csrf::session();

/*
 * Tracking without an account: the order number and the phone that placed it.
 *
 * Two things protect this page, and both are necessary.
 *
 * The first is that a miss is a miss. A wrong number, a right number with the
 * wrong phone, and an order that was never placed all produce the same sentence
 * and the same status code. Nothing here says "no such order" as distinct from
 * "wrong phone", because the difference between those two answers is exactly
 * what tells someone walking the number space that they have found a real one.
 *
 * The second is the rate limit. The order number is five characters from a
 * thirty-two letter alphabet -- about thirty-three million -- which is far too
 * many to guess by hand and nothing at all to a script. Ten tries per ten
 * minutes per address makes the search take longer than the shop will exist.
 */

$order   = null;
$message = null;
$number  = (string) ($_GET['number'] ?? '');
$phone   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    $number = (string) ($_POST['number'] ?? '');
    $phone  = (string) ($_POST['phone'] ?? '');

    if (!RateLimiter::make()->check('track', RateLimiter::client())) {
        $message = 'محاولات كثيرة. انتظر عشر دقائق ثم أعد المحاولة.';
        http_response_code(429);
    } else {
        $order = OrderService::make()->track($number, $phone);

        if ($order === null) {
            // Deliberately one sentence for every kind of miss.
            $message = 'لم نجد طلبًا بهذا الرقم ورقم الهاتف هذا. تأكّد من الاثنين.';
            http_response_code(404);
        }
    }
}

$title     = 'تتبّع طلبك — ' . Config::get('app.name');
$current   = '';
$cartCount = Shop::cartCount();

$body = static function () use ($order, $message, $number, $phone): void {
    if ($order !== null) {
        require dirname(__DIR__) . '/src/View/order-detail.php';

        return;
    }
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <div>
                <h1 class="page__title">تتبّع طلبك</h1>
                <p class="page__lede">
                    رقم الطلب ورقم الهاتف الذي طلبت به. بلا حساب وبلا كلمة مرور.
                </p>
            </div>
        </div>

        <?php if ($message !== null) { ?>
            <p class="alert alert--error"><?= e($message) ?></p>
        <?php } ?>

        <form class="track-form" method="post" action="/track.php">
            <?= Csrf::field() ?>

            <label class="field">
                <span class="field__label">رقم الطلب</span>
                <input type="text" name="number" value="<?= e($number) ?>" required
                       maxlength="12" placeholder="TS-4X7K2" autocomplete="off"
                       style="letter-spacing:2px;text-transform:uppercase">
            </label>

            <label class="field">
                <span class="field__label">رقم الهاتف</span>
                <input type="tel" name="phone" value="<?= e($phone) ?>" required
                       maxlength="40" autocomplete="tel">
            </label>

            <button type="submit" class="button button--primary button--block">اعرض الطلب</button>
        </form>
    </main>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
