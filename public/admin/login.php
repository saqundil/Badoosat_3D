<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\RateLimiter;
use App\Support\Response;

Csrf::session();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();

    if (!RateLimiter::make()->check('login', RateLimiter::client())) {
        http_response_code(429);
        $error = 'محاولات كثيرة. انتظر ربع ساعة ثم أعد المحاولة.';
    } elseif (Auth::attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        Response::redirect('/admin/');
    } else {
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
    }
}

if (Auth::check()) {
    Response::redirect('/admin/');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>دخول لوحة التحكم</title>
    <?php /* Same three files, same order, as src/View/admin.php: admin.css
             only names tokens, and hero.css carries Cairo 400/900. */ ?>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/hero.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= e(ASSETS) ?>">
</head>
<body class="admin admin--centred">

<form class="panel panel--narrow" method="post" action="/admin/login.php">
    <h1>لوحة التحكم</h1>

    <?php if ($error !== null) { ?>
        <p class="alert alert--error"><?= e($error) ?></p>
    <?php } ?>

    <?= Csrf::field() ?>

    <label class="field">
        <span>اسم المستخدم</span>
        <input type="text" name="username" autocomplete="username" required autofocus>
    </label>

    <label class="field">
        <span>كلمة المرور</span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="button button--primary">دخول</button>
</form>

</body>
</html>
