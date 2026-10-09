<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\ProductManagementService;
use App\Support\Auth;
use App\Support\Csrf;

Auth::require();
Csrf::session();

$service = ProductManagementService::make();
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->paginate($search, $page);

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);

$title = 'المنتجات';
$current = 'products';

$body = static function () use ($result, $search, $notice): void {
    ?>
    <main class="admin__body">
        <div class="admin__bar-group" style="justify-content:space-between">
            <h1>المنتجات</h1>
            <a class="button button--primary" href="/admin/product-edit.php">منتج جديد</a>
        </div>

        <?php if ($notice !== null) { ?><p class="alert alert--ok"><?= e($notice) ?></p><?php } ?>

        <form class="panel" method="get" action="/admin/products.php">
            <label class="field">
                <span>البحث بالاسم أو المُعرّف</span>
                <input type="search" name="q" value="<?= e($search) ?>">
            </label>
            <button type="submit" class="button">بحث</button>
        </form>

        <div class="panel" style="margin-top:18px">
            <table class="table">
                <thead>
                    <tr><th>الاسم</th><th>النوع</th><th>العرض</th><th>السعر الأساسي</th><th>3D</th><th>التخصيص</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($result['items'] as $product) { ?>
                        <tr>
                            <td><?= e($product['name']) ?><br><code><?= e($product['slug']) ?></code></td>
                            <td><?= e($product['garment']) ?></td>
                            <td><?= e($product['presentation_type']) ?></td>
                            <td><?= e(money((int) $product['base_price_cents'])) ?></td>
                            <td><?= (int) $product['show_3d'] ? 'نعم' : 'لا' ?></td>
                            <td><?= (int) $product['customization_enabled'] ? 'نعم' : 'لا' ?></td>
                            <td><a class="button" href="/admin/product-edit.php?id=<?= (int) $product['id'] ?>">تحرير</a></td>
                        </tr>
                    <?php } ?>
                    <?php if ($result['items'] === []) { ?>
                        <tr><td colspan="7">لا توجد منتجات مطابقة.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($result['pages'] > 1) { ?>
            <nav class="admin__bar-group" aria-label="صفحات المنتجات" style="margin-top:18px">
                <?php for ($number = 1; $number <= $result['pages']; $number++) { ?>
                    <a class="button<?= $number === $result['page'] ? ' button--primary' : '' ?>"
                       href="/admin/products.php?page=<?= $number ?>&amp;q=<?= rawurlencode($search) ?>"><?= $number ?></a>
                <?php } ?>
            </nav>
        <?php } ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
