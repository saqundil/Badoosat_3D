<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Support\Csrf;
use App\Support\Response;
use App\Support\Shop;

Csrf::session();

/*
 * POST only, and with a token. A logout reachable by GET can be fired from an
 * <img src> on any page on the internet, which is a small nuisance on its own
 * and a way to empty somebody's session mid-checkout.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::redirect('/account/');
}

Csrf::require($_POST);
Shop::signOut();

Response::redirect('/');
