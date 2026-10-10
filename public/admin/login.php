<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Lang;
use App\Support\RateLimiter;
use App\Support\Response;

/* The shell is not loaded here, and admin_icon() lives with it. */
require_once dirname(__DIR__, 2) . '/src/View/admin/helpers.php';

Csrf::session();

/*
 * The way in.
 *
 * The language switch works here as it does everywhere else in the dashboard
 * (?lang=, remembered in the session), which it did not before: the one screen
 * an administrator meets before anything else was Arabic whatever they chose.
 * Auth::require() is what applies the choice on every other page, and this page
 * is the one that cannot call it.
 */
Lang::applyAdminChoice();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();

    if (!RateLimiter::make()->check('login', RateLimiter::client())) {
        http_response_code(429);
        $error = Lang::get('admin.login.too_many');
    } elseif (Auth::attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        Response::redirect('/admin/');
    } else {
        $error = Lang::get('admin.login.wrong');
    }
}

if (Auth::check()) {
    Response::redirect('/admin/');
}

$locales = Lang::adminLocales();
/* What was typed stays; the password never does. */
$username = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['username'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= Lang::isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f6fc">
    <title><?= e(Lang::get('admin.login.title')) ?> · <?= e(Lang::get('admin.title')) ?></title>
    <?php /* Same three files, same order, as src/View/admin.php: admin.css
             only names tokens, and hero.css carries Cairo 400/900. */ ?>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/hero.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= e(ASSETS) ?>">
</head>
<body class="admin admin--centred">

<main class="login">
    <div class="login__brand">
        <?php require dirname(__DIR__, 2) . '/src/View/brand-mark.php'; ?>
    </div>

    <form class="login__card" method="post" action="/admin/login.php">
        <h1 class="login__title"><?= e(Lang::get('admin.login.title')) ?></h1>
        <p class="login__hint"><?= e(Lang::get('admin.login.hint')) ?></p>

        <?php /*
            One message for both fields, and said once.
            Which of the two was wrong is deliberately not answered -- that
            answer tells someone guessing which half they already have -- so
            the message belongs to the pair, and both fields point at it.
        */ ?>
        <?php if ($error !== null) { ?>
            <p class="login__error" id="login-error" role="alert">
                <?= admin_icon('alert') ?><span><?= e($error) ?></span>
            </p>
        <?php } ?>

        <?= Csrf::field() ?>

        <div class="form-field" data-field>
            <label class="form-field__label" for="login-username"><?= e(Lang::get('admin.login.username')) ?></label>
            <input class="input" type="text" id="login-username" name="username" value="<?= e($username) ?>"
                   autocomplete="username" dir="ltr" spellcheck="false" autocapitalize="off" required autofocus
                   <?= $error !== null ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>>
        </div>

        <div class="form-field" data-field>
            <label class="form-field__label" for="login-password"><?= e(Lang::get('admin.login.password')) ?></label>
            <input class="input" type="password" id="login-password" name="password"
                   autocomplete="current-password" dir="ltr" spellcheck="false" required
                   <?= $error !== null ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>>
        </div>

        <button type="submit" class="button button--primary login__submit"><?= e(Lang::get('admin.login.submit')) ?></button>
    </form>

    <?php if (count($locales) > 1) { ?>
        <nav class="login__langs" aria-label="<?= e(Lang::get('admin.lang.label')) ?>">
            <ul class="lang-switch">
                <?php foreach ($locales as $code) { ?>
                    <li>
                        <?php if ($code === Lang::locale()) { ?>
                            <span class="lang-switch__item" aria-current="true" lang="<?= e($code) ?>"><?= e(Lang::nativeName($code)) ?></span>
                        <?php } else { ?>
                            <a class="lang-switch__item" href="/admin/login.php?lang=<?= e(rawurlencode($code)) ?>"
                               lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"><?= e(Lang::nativeName($code)) ?></a>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
        </nav>
    <?php } ?>
</main>

</body>
</html>
