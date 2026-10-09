<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\GarmentService;
use App\Service\ValidationException;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Response;

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('POST only.', 405);
}

Csrf::require($_POST);

$service = GarmentService::make();
$id = max(0, (int) ($_POST['id'] ?? 0));

try {
    $id = $service->save($_POST);
} catch (ValidationException $error) {
    Csrf::session();
    $_SESSION['errors'] = $error->errors();
    $_SESSION['old'] = $_POST;
    Response::redirect('/admin/garments.php' . ($id > 0 ? '?id=' . $id : ''));
}

Csrf::session();
$_SESSION['notice'] = 'حُفظ النوع.';
Response::redirect('/admin/garments.php?id=' . $id);
