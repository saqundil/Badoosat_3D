<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\CustomerService;
use App\Service\ValidationException;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\RateLimiter;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

if (Shop::customerId() !== null) {
    Response::redirect('/account/');
}

$errors = [];
$input  = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);
    $input = [...$input, ...array_intersect_key($_POST, $input)];

    if (!RateLimiter::make()->check('login', RateLimiter::client())) {
        $errors['rate'] = 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.';
    } else {
        try {
            $customer = CustomerService::make()->register($_POST);
            Shop::signIn($customer);
            Response::redirect('/account/');
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
    }
}

$title     = 'حساب جديد — ' . Config::get('app.name');
$current   = '';
$cartCount = Shop::cartCount();

$body = static function () use ($errors, $input): void {
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <div>
                <h1 class="page__title">حساب جديد</h1>
                <p class="page__lede">لست مضطرًا — الطلب كضيف يعمل تمامًا.</p>
            </div>
        </div>

        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <form class="track-form" method="post" action="/account/register.php">
            <?= Csrf::field() ?>

            <label class="field">
                <span class="field__label">الاسم</span>
                <input type="text" name="name" value="<?= e($input['name']) ?>" required
                       maxlength="160" autocomplete="name">
            </label>

            <label class="field">
                <span class="field__label">البريد الإلكتروني</span>
                <input type="email" name="email" value="<?= e($input['email']) ?>" required
                       maxlength="190" autocomplete="email">
            </label>

            <label class="field">
                <span class="field__label">رقم الهاتف</span>
                <input type="tel" name="phone" value="<?= e($input['phone']) ?>" required
                       maxlength="40" autocomplete="tel">
            </label>

            <label class="field">
                <span class="field__label">كلمة المرور</span>
                <input type="password" name="password" required minlength="8"
                       autocomplete="new-password">
                <span class="hint hint--tight">ثمانية أحرف على الأقل. لا شروط أخرى.</span>
            </label>

            <button type="submit" class="button button--primary button--block">أنشئ الحساب</button>
        </form>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/layout.php';
