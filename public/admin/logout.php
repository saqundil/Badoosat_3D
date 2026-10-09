<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Response;

Csrf::session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    Auth::logout();
}

Response::redirect('/admin/login.php');
