<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\ProductManagementService;
use App\Service\ValidationException;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Response;
use App\Support\UploadLimit;

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('POST only.', 405);
}

if (UploadLimit::postBodyExceeded()) {
    /*
     * Back to the form with a message beside the field, not a bare JSON 413.
     *
     * A body over post_max_size arrives with $_POST and $_FILES both empty --
     * PHP discarded them before any of this ran -- so there is no id to return
     * to and no token to trust. The editor is where the administrator was, and
     * the session is the only thing that survives the discarded body.
     */
    Csrf::session();
    $_SESSION['errors'] = ['product_images' =>
        'حجم الطلب أكبر من المسموح. ' . UploadLimit::message()
        . ' جرّب صورة أصغر أو ارفعها على دفعات.'];
    Response::redirect('/admin/product-edit.php');
}

Csrf::require($_POST);

$service = ProductManagementService::make();
$action = (string) ($_POST['action'] ?? 'save');
$id = max(0, (int) ($_POST['id'] ?? 0));

if ($action === 'delete') {
    /*
     * Refused when order history depends on it. The message names the number
     * of lines and points at deactivation, so it belongs on the page the
     * administrator was just looking at rather than on a blank error screen.
     */
    try {
        $service->delete($id);
    } catch (ValidationException $error) {
        Csrf::session();
        $_SESSION['errors'] = $error->errors();
        Response::redirect('/admin/product-edit.php?id=' . $id);
    }

    Csrf::session();
    $_SESSION['notice'] = 'حُذف المنتج.';
    Response::redirect('/admin/products.php');
}

if ($action === 'deactivate' || $action === 'activate') {
    $service->setActive($id, $action === 'activate');
    Csrf::session();
    $_SESSION['notice'] = $action === 'activate'
        ? 'أُعيد تفعيل المنتج وظهر في المتجر.'
        : 'عُطّل المنتج: لم يعد يظهر في المتجر، وسجلّ الطلبات لم يتغيّر.';
    Response::redirect('/admin/product-edit.php?id=' . $id);
}

if ($action !== 'save') {
    Response::error('Unknown action.', 400);
}

try {
    $id = $service->save($_POST, $_FILES);
} catch (ValidationException $error) {
    Csrf::session();
    $_SESSION['errors'] = $error->errors();
    $_SESSION['old'] = $_POST;
    Response::redirect('/admin/product-edit.php' . ($id > 0 ? '?id=' . $id : ''));
}

Csrf::session();
$_SESSION['notice'] = 'حُفظ المنتج.';
Response::redirect('/admin/product-edit.php?id=' . $id);
