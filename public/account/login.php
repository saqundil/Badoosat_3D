<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\CustomerService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\RateLimiter;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

if (Shop::customerId() !== null) {
    Response::redirect('/account/');
}

$next  = (string) ($_GET['next'] ?? '/account/');
$error = null;
$email = '';

/*
 * Only ever back into this site, and only to a path. An open redirect on a
 * login page is how a phishing link borrows the shop's own domain to look
 * legitimate in a customer's address bar.
 */
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
    $next = '/account/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    $email = trim((string) ($_POST['email'] ?? ''));
    $next  = (string) ($_POST['next'] ?? '/account/');

    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
        $next = '/account/';
    }

    if (!RateLimiter::make()->check('login', RateLimiter::client())) {
        $error = 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.';
    } else {
        $customer = CustomerService::make()->attempt($email, (string) ($_POST['password'] ?? ''));

        if ($customer === null) {
            // One message for a wrong password and for an address that has no
            // account. Telling them apart is a way to test which addresses
            // have shopped here.
            $error = 'البريد أو كلمة المرور غير صحيحة.';
        } else {
            // Signing in brings whatever was in the guest cart along with it.
            Shop::signIn($customer);
            Response::redirect($next);
        }
    }
}

$title     = 'تسجيل الدخول — ' . Config::get('app.name');
$current   = '';
$cartCount = Shop::cartCount();

$body = static function () use ($error, $email, $next): void {
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <div>
                <h1 class="page__title">تسجيل الدخول</h1>
                <p class="page__lede">
                    الحساب اختياري: تقدر تطلب كضيف بلا واحد. فائدته أن عنوانك
                    وطلباتك السابقة تبقى محفوظة.
                </p>
            </div>
        </div>

        <?php if ($error !== null) { ?>
            <p class="alert alert--error"><?= e($error) ?></p>
        <?php } ?>

        <form class="track-form" method="post" action="/account/login.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">

            <label class="field">
                <span class="field__label">البريد الإلكتروني</span>
                <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email">
            </label>

            <label class="field">
                <span class="field__label">كلمة المرور</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>

            <button type="submit" class="button button--primary button--block">دخول</button>

            <p class="hint" style="margin-top:12px">
                ما عندك حساب؟ <a href="/account/register.php">أنشئ واحدًا</a>،
                أو <a href="/track.php">تتبّع طلبًا</a> برقمه ورقم هاتفك.
            </p>
        </form>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/layout.php';
