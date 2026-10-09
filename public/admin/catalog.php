<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\DesignRepository;
use App\Repository\ProductRepository;
use App\Service\ProductService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Price;
use App\Support\Response;
use App\Support\Validator;

Auth::require();

$products = ProductRepository::make();
$designs  = DesignRepository::make();
$service  = ProductService::make();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'stock') {
        /*
         * Price and stock, per variant.
         *
         * The price obeys the same rule the product form obeys -- Price::MIN to
         * Price::MAX, and the identical sentence when it does not -- because
         * they are the same question asked twice. This box used to clamp a
         * negative silently to zero, which is the one value the product form
         * exists to forbid: it reaches the catalogue reading as free.
         *
         * And the conversion is Price::cents(), which casts to float before
         * multiplying. Written here as `(int) $price * 100` it truncated first,
         * so every 19.99 an owner typed was stored as 19.00.
         */
        $rows = (array) ($_POST['variant'] ?? []);
        $rejected = [];

        foreach ($rows as $variantId => $values) {
            $price = $values['price'] ?? '';
            $stock = $values['stock'] ?? '';

            if (!is_numeric($price) || (float) $price < Price::MIN || (float) $price > Price::MAX) {
                $rejected['variant_' . (int) $variantId] =
                    'سعر المتغيّر ' . (int) $variantId . ' يجب أن يكون بين '
                    . Price::MIN . ' و ' . Price::MAX . '.';
            }

            if (!is_numeric($stock) || (int) $stock < 0) {
                $rejected['stock_' . (int) $variantId] =
                    'مخزون المتغيّر ' . (int) $variantId . ' يجب أن يكون صفرًا أو أكثر.';
            }
        }

        if ($rejected !== []) {
            $errors = $rejected;
        } else {
            $stmt = Db::pdo()->prepare(
                'UPDATE product_variants SET price_cents = ?, stock = ?, is_active = ? WHERE id = ?'
            );

            foreach ($rows as $variantId => $values) {
                $stmt->execute([
                    Price::cents($values['price']),
                    (int) $values['stock'],
                    isset($values['active']) ? 1 : 0,
                    (int) $variantId,
                ]);
            }

            $_SESSION['notice'] = 'حُفظ المخزون والأسعار.';
        }
    }

    if ($action === 'design') {
        /*
         * Closed in phase 1 of the product rebuild.
         *
         * A product's print is now a file on the product row -- artwork_path,
         * with its position and scale -- and nothing writes `designs`,
         * `design_products` or `design_layers` any more. Leaving this one door
         * open would have contradicted that: a design saved here would be a
         * row in a table the rest of the system has stopped maintaining.
         *
         * The screen below still READS those tables, because they still hold
         * live order history: 20 order lines and one cart line point at design
         * #1. Deleting the tables is phase 4, by decision, after the inventory
         * has been reviewed. Until then the listing is a record, not an editor.
         */
        $_SESSION['errors'] = ['design' =>
            'إدارة التصاميم الجاهزة متوقّفة. صار العمل الفني جزءًا من المنتج نفسه: '
            . 'افتح المنتج وارفع عمله الفني من شاشة المنتجات. '
            . 'التصاميم القائمة معروضة للاطّلاع فقط لأن سجلّ الطلبات ما زال يشير إليها.'];
        Response::redirect('/admin/catalog.php');
    }

    if (false) {
        $v = new Validator($_POST);
        $v->required('name', 'اسم التصميم')->maxLength('name', 160, 'اسم التصميم');
        $v->required('slug', 'المُعرِّف')->maxLength('slug', 120, 'المُعرِّف');
        $v->assetPath('print_path', 'ملف الطباعة');
        $v->assetPath('thumb_path', 'المصغّرة', '', true);
        $v->decimal('surcharge', Price::SURCHARGE_MIN, Price::SURCHARGE_MAX, 'السعر الإضافي');
        $v->integer('sort_order', 0, 999, 'الترتيب');

        if (!$v->passes()) {
            $errors = $v->errors();
        } else {
            $values = [
                'slug'            => (string) $_POST['slug'],
                'name'            => (string) $_POST['name'],
                'summary'         => mb_substr(trim((string) ($_POST['summary'] ?? '')), 0, 255) ?: null,
                'print_path'      => (string) $_POST['print_path'],
                'thumb_path'      => (string) ($_POST['thumb_path'] ?? '') ?: null,
                'surcharge_cents' => Price::cents($_POST['surcharge']),
                'is_active'       => isset($_POST['is_active']) ? 1 : 0,
                'sort_order'      => (int) $_POST['sort_order'],
            ];

            $designId = (int) ($_POST['design_id'] ?? 0);

            if ($designId > 0) {
                $designs->update($designId, $values);
            } else {
                $designId = $designs->insert($values);
            }

            $designs->setProducts($designId, array_map('intval', (array) ($_POST['products'] ?? [])));

            $_SESSION['notice'] = 'حُفظ التصميم.';
        }
    }

    if ($errors === []) {
        /*
         * The listing is served from a file cache so it can be rendered without
         * touching the database at all. The price of that is that a save has to
         * say so -- nothing else will, and a shop owner who changes a price and
         * still sees the old one on the site will change it again.
         */
        $service->forgetCache();

        Response::redirect('/admin/catalog.php');
    }
}

$notice = $_SESSION['notice'] ?? null;

/*
 * Errors that survived a redirect, as the product editor does it.
 *
 * This page used to keep its errors in a local variable only, which worked
 * while every failure re-rendered the page in the same request. The disabled
 * design write point redirects instead -- there is nothing to re-render and no
 * draft worth keeping -- so its message would have vanished without this.
 */
$errors = array_merge($errors, (array) ($_SESSION['errors'] ?? []));
unset($_SESSION['notice'], $_SESSION['errors']);

$allProducts = $products->findMany(array_map(
    static fn (array $p): int => (int) $p['id'],
    $products->listing('newest', '')
));

$editing = null;

if (isset($_GET['design'])) {
    $editing = $designs->find((int) $_GET['design']);

    if ($editing !== null) {
        $editing['products'] = $designs->productsFor((int) $editing['id']);
    }
}

$title   = 'الكتالوج';
$current = 'catalog';

$body = static function () use ($products, $designs, $allProducts, $editing, $notice, $errors): void {
    ?>
    <main class="admin__body">
        <h1>الكتالوج</h1>

        <?php if ($notice !== null) { ?><p class="alert alert--ok"><?= e($notice) ?></p><?php } ?>
        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <?php /* --------------------------------------------- stock and price */ ?>
        <h2>المخزون والأسعار</h2>
        <p class="hint">
            المخزون على اللون والمقاس وحدهما. التصاميم لا مخزون لها — تُطبع عند الطلب.
        </p>

        <form method="post" action="/admin/catalog.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="stock">

            <?php foreach ($allProducts as $product) {
                $variants = $products->variantsFor([(int) $product['id']]); ?>
                <div class="panel" style="margin-bottom:18px">
                    <h3><?= e($product['name']) ?> <code><?= e($product['slug']) ?></code></h3>

                    <table class="table">
                        <thead>
                            <tr><th>اللون</th><th>المقاس</th><th>SKU</th><th>السعر</th><th>المخزون</th><th>متاح</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($variants as $variant) { ?>
                                <tr>
                                    <td>
                                        <span class="slide__swatch" style="background:<?= e($variant['color_hex']) ?>"></span>
                                        <?= e($variant['color_name']) ?>
                                    </td>
                                    <td><?= e($variant['size_code']) ?></td>
                                    <td><code><?= e($variant['sku']) ?></code></td>
                                    <td>
                                        <input type="number" min="0" step="1" style="width:90px"
                                               name="variant[<?= (int) $variant['id'] ?>][price]"
                                               value="<?= (int) round((int) $variant['price_cents'] / 100) ?>"
                                               aria-label="السعر">
                                    </td>
                                    <td>
                                        <input type="number" min="0" style="width:90px"
                                               name="variant[<?= (int) $variant['id'] ?>][stock]"
                                               value="<?= (int) $variant['stock'] ?>"
                                               aria-label="المخزون">
                                    </td>
                                    <td>
                                        <input type="checkbox" name="variant[<?= (int) $variant['id'] ?>][active]"
                                               <?= (int) $variant['is_active'] ? 'checked' : '' ?>
                                               aria-label="متاح">
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>

            <button type="submit" class="button button--primary">احفظ</button>
        </form>

        <?php /* ------------------------------ ready-made designs: read only */ ?>
        <h2 style="margin-top:34px">التصاميم الجاهزة <span class="badge">متوقّفة</span></h2>

        <p class="alert alert--notice" style="margin-bottom:14px">
            إدارة التصاميم الجاهزة متوقّفة منذ إعادة بناء نموذج المنتجات.
            صار العمل الفني جزءًا من المنتج نفسه: افتح المنتج من
            <a href="/admin/products.php">شاشة المنتجات</a> وارفع عمله الفني هناك.
            <br>
            الجدول أدناه للاطّلاع فقط — سجلّ الطلبات ما زال يشير إلى هذه التصاميم،
            فهي محفوظة كسجلّ ولا تُحذف.
        </p>

        <table class="table" style="margin-bottom:20px">
            <thead>
                <tr><th></th><th>الاسم</th><th>السعر الإضافي</th><th>على أي منتج</th><th>الحالة</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($designs->all() as $design) { ?>
                    <tr>
                        <td>
                            <?php if ($design['thumb_path']) { ?>
                                <img src="<?= e($design['thumb_path']) ?>" alt="" width="40" height="40"
                                     style="border-radius:6px;object-fit:cover">
                            <?php } ?>
                        </td>
                        <td><?= e($design['name']) ?><br><code><?= e($design['slug']) ?></code></td>
                        <td><?= e(money((int) $design['surcharge_cents'])) ?></td>
                        <td><?= count($designs->productsFor((int) $design['id'])) ?> منتج</td>
                        <td><span class="badge<?= (int) $design['is_active'] ? ' badge--on' : '' ?>">
                            <?= (int) $design['is_active'] ? 'منشور' : 'معطّل' ?>
                        </span></td>
                        <td><a class="button" href="/admin/catalog.php?design=<?= (int) $design['id'] ?>">تحرير</a></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <?php /*
         * The design editor, kept in the file and not rendered.
         *
         * Phase 1 of the product rebuild stopped every write to `designs`,
         * `design_products` and `design_layers`. The markup stays because
         * phase 4 decides what happens to these tables, and deleting a
         * working editor before that decision is made would be deleting
         * the only way to undo it. The write point above refuses the post
         * regardless, so this is belt and braces, not the lock itself.
         */ ?>
        <?php if (false) { ?>
        <form class="panel" method="post" action="/admin/catalog.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="design">
            <input type="hidden" name="design_id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <h3><?= $editing === null ? 'تصميم جديد' : 'تحرير ' . e($editing['name']) ?></h3>

            <div class="stat-row">
                <label class="field">
                    <span>المُعرِّف</span>
                    <input type="text" name="slug" value="<?= e((string) ($editing['slug'] ?? '')) ?>" required>
                </label>
                <label class="field">
                    <span>الاسم</span>
                    <input type="text" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" required>
                </label>
            </div>

            <label class="field">
                <span>وصف قصير</span>
                <input type="text" name="summary" value="<?= e((string) ($editing['summary'] ?? '')) ?>">
            </label>

            <div class="stat-row">
                <label class="field">
                    <span>ملف الطباعة (مسار تحت public)</span>
                    <input type="text" name="print_path" required
                           value="<?= e((string) ($editing['print_path'] ?? '/uploads/designs/')) ?>">
                </label>
                <label class="field">
                    <span>المصغّرة</span>
                    <input type="text" name="thumb_path"
                           value="<?= e((string) ($editing['thumb_path'] ?? '')) ?>">
                </label>
            </div>

            <div class="stat-row">
                <label class="field">
                    <span>السعر الإضافي (دينار)</span>
                    <input type="number" name="surcharge" min="0" max="100000"
                           value="<?= (int) round((int) ($editing['surcharge_cents'] ?? 0) / 100) ?>" required>
                </label>
                <label class="field">
                    <span>الترتيب</span>
                    <input type="number" name="sort_order" min="0" max="999"
                           value="<?= (int) ($editing['sort_order'] ?? 0) ?>" required>
                </label>
            </div>

            <fieldset>
                <legend>متاح على</legend>
                <?php foreach ($allProducts as $product) { ?>
                    <label class="field field--check">
                        <input type="checkbox" name="products[]" value="<?= (int) $product['id'] ?>"
                            <?= in_array((int) $product['id'], $editing['products'] ?? [], true) ? 'checked' : '' ?>>
                        <span><?= e($product['name']) ?></span>
                    </label>
                <?php } ?>
            </fieldset>

            <label class="field field--check">
                <input type="checkbox" name="is_active" <?= ($editing === null || (int) $editing['is_active']) ? 'checked' : '' ?>>
                <span>منشور</span>
            </label>

            <button type="submit" class="button button--primary">احفظ التصميم</button>
            <?php if ($editing !== null) { ?>
                <a class="button" href="/admin/catalog.php">إلغاء</a>
            <?php } ?>
        </form>
        <?php } ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
