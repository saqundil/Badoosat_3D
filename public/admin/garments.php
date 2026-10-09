<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\GarmentService;
use App\Service\ProductManagementService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\GarmentModels;
use App\Support\Response;

Auth::require();
Csrf::session();

/*
 * Garment types: the cuts the shop sells, and what each is made of.
 *
 * A type is upstream of every product built on it, which is why it is its own
 * screen and why it comes first in the navigation. Defining one is a decision
 * about the sewing floor -- this cut, in these colours, in these sizes -- and a
 * product later picks from what the cut offers rather than from everything.
 *
 * The model is chosen from config/garment-models.php and never typed or
 * uploaded, the same rule the hero follows.
 */

$service = GarmentService::make();
$products = ProductManagementService::make();

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0 ? $service->find($id) : null;

if ($id > 0 && $editing === null) {
    Response::redirect('/admin/garments.php');
}

$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? [];
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['errors'], $_SESSION['old'], $_SESSION['notice']);

$garment = $editing ?? $service->blank();

/* A refused save comes back with what was typed, not with what is stored. */
if ($old !== []) {
    $garment = array_merge($garment, [
        'key' => (string) ($old['key'] ?? $garment['key']),
        'name_ar' => (string) ($old['name_ar'] ?? $garment['name_ar']),
        'name_en' => (string) ($old['name_en'] ?? $garment['name_en']),
        'model_key' => (string) ($old['model_key'] ?? $garment['model_key']),
        'print_area_w_cm' => (string) ($old['print_area_w_cm'] ?? $garment['print_area_w_cm']),
        'print_area_h_cm' => (string) ($old['print_area_h_cm'] ?? $garment['print_area_h_cm']),
        'sort_order' => (int) ($old['sort_order'] ?? $garment['sort_order']),
        'base_product_id' => (int) ($old['base_product_id'] ?? $garment['base_product_id'] ?? 0),
        'is_active' => isset($old['key']) ? (int) !empty($old['is_active']) : (int) $garment['is_active'],
        'colors' => array_map('intval', (array) ($old['colors'] ?? $garment['colors'])),
        'sizes' => array_map('intval', (array) ($old['sizes'] ?? $garment['sizes'])),
    ]);
}

$types = $service->all();
$baseCandidates = $editing === null ? [] : $service->baseProductCandidates((int) $garment['id']);
$palette = $products->colors();
$sizeChart = $products->sizes();
$models = GarmentModels::all();
$sharedKeys = GarmentModels::sharedKeys();

$title = 'الأنواع — لوحة التحكم';
$current = 'garments';

$body = static function () use (
    $types, $garment, $palette, $sizeChart, $models, $sharedKeys, $baseCandidates,
    $errors, $notice, $editing
): void {
    ?>
    <main class="admin__main">
        <div class="page__head">
            <h1 class="page__title">أنواع القطع</h1>
        </div>

        <?php if ($notice !== null) { ?>
            <p class="alert alert--ok"><?= e($notice) ?></p>
        <?php } ?>

        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <?php /*
         * The shared-model notice.
         *
         * Both types currently draw on tshirt.glb because only that mesh has
         * measured print geometry. That is a placeholder and it is easy to
         * forget, so it is printed here on the screen rather than left in a
         * comment in config/garment-models.php.
         */ ?>
        <?php if ($sharedKeys !== []) { ?>
            <p class="alert alert--notice">
                <strong>بند مؤجَّل:</strong>
                <?= count($sharedKeys) === 1 ? 'نوع واحد يعرض' : 'الأنواع التالية تعرض' ?>
                مجسمًا ليس مجسمه الخاص:
                <?php foreach ($sharedKeys as $index => $key) { ?>
                    <?= $index > 0 ? '، ' : '' ?><strong><?= e(GarmentModels::labelFor($key)) ?></strong>
                <?php } ?>
                — كلّها مرسومة على <code>tshirt.glb</code> لأنه المجسم الوحيد الذي قِيست
                مساحة طباعته. الزبون يرى شكل القميص نفسه للنوعين.
                <br>
                لإغلاق البند: انقل المجسم الثاني إلى <code>public/models/</code>، ثم شغّل
                <code>tools/measure-print-area.mjs</code> عليه، ثم أضف مدخلًا في
                <code>config/garments.php</code> وأشِر إليه من السجلّ.
            </p>
        <?php } ?>

        <?php /*
         * Why the customer's editor may be empty.
         *
         * A cut only reaches the editor if it names a base product that can
         * still be sold -- that product is what a custom order becomes, and it
         * carries the price, the stock, the colours and the sizes. A type
         * without one is left out of the editor entirely, so the reason belongs
         * here, on the screen where it can be fixed, rather than being
         * discovered by a customer who finds nothing to choose.
         */ ?>
        <?php $blocked = array_values(array_filter($types,
            static fn (array $t): bool => $t['editor_problem'] !== null)); ?>
        <?php if ($blocked !== []) { ?>
            <p class="alert alert--notice">
                <strong>لا تظهر في محرر الزبون:</strong>
                <?php foreach ($blocked as $type) { ?>
                    <br>
                    <strong><?= e($type['name_ar']) ?></strong> — <?= e((string) $type['editor_problem']) ?>
                <?php } ?>
                <br>
                <a href="/design.php">صفحة المحرر</a> تعرض الأنواع الجاهزة وحدها.
            </p>
        <?php } ?>

        <table class="table" style="margin-bottom:24px">
            <thead>
                <tr>
                    <th>النوع</th><th>المجسم</th><th>المنتج الأساسي</th><th>مساحة الطباعة</th>
                    <th>الألوان</th><th>المقاسات</th><th>المنتجات</th><th>الحالة</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types as $type) { ?>
                    <tr>
                        <td>
                            <?= e($type['name_ar']) ?><br>
                            <code><?= e($type['key']) ?></code>
                        </td>
                        <td>
                            <?php if ($type['model_missing']) { ?>
                                <span class="badge badge--warn">مفقود من السجلّ</span><br>
                                <code><?= e($type['model_key']) ?></code>
                            <?php } else { ?>
                                <?= e($type['model_label']) ?>
                                <?php if ($type['model_shared']) { ?>
                                    <br><span class="badge">مجسم مشترك</span>
                                <?php } ?>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($type['base_product_name'] === null) { ?>
                                <span class="badge badge--warn">غير مضبوط</span>
                            <?php } else { ?>
                                <?= e($type['base_product_name']) ?>
                                <?php if ($type['base_product_printed']) { ?>
                                    <br><span class="badge badge--warn">مطبوع</span>
                                <?php } ?>
                            <?php } ?>
                        </td>
                        <td><?= e(rtrim(rtrim((string) $type['print_area_w_cm'], '0'), '.')) ?>×<?=
                            e(rtrim(rtrim((string) $type['print_area_h_cm'], '0'), '.')) ?> سم</td>
                        <td><?= count($type['colors']) ?></td>
                        <td><?= count($type['sizes']) ?></td>
                        <td><?= (int) $type['product_count'] ?></td>
                        <td>
                            <span class="badge<?= (int) $type['is_active'] ? ' badge--on' : '' ?>">
                                <?= (int) $type['is_active'] ? 'مفعَّل' : 'معطَّل' ?>
                            </span>
                        </td>
                        <td><a class="button" href="/admin/garments.php?id=<?= (int) $type['id'] ?>">تحرير</a></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <form class="panel" method="post" action="/admin/garment-save.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $garment['id'] ?>">

            <h2><?= $editing === null ? 'نوع جديد' : 'تحرير: ' . e($garment['name_ar']) ?></h2>

            <div class="grid grid--two">
                <label class="field">
                    <span>الاسم بالعربية</span>
                    <input type="text" name="name_ar" maxlength="120" required
                           value="<?= e($garment['name_ar']) ?>">
                </label>

                <label class="field">
                    <span>الاسم بالإنجليزية</span>
                    <input type="text" name="name_en" maxlength="120" required
                           value="<?= e($garment['name_en']) ?>">
                </label>
            </div>

            <label class="field">
                <span>المُعرّف</span>
                <input type="text" name="key" maxlength="60" required
                       value="<?= e($garment['key']) ?>"
                       pattern="[a-z0-9]+(-[a-z0-9]+)*">
                <small>أحرف إنجليزية صغيرة وأرقام وشرطات. لا يُغيَّر بعد نشر منتجات عليه.</small>
            </label>

            <label class="field">
                <span>المجسم</span>
                <select name="model_key" required>
                    <?php foreach ($models as $key => $model) { ?>
                        <option value="<?= e($key) ?>"<?= $garment['model_key'] === $key ? ' selected' : '' ?>>
                            <?= e($model['label']) ?><?= !empty($model['shared_model']) ? ' — مجسم مشترك مؤقتًا' : '' ?>
                        </option>
                    <?php } ?>
                </select>
                <small>
                    المجسمات مسجَّلة في <code>config/garment-models.php</code> ويضبطها المبرمج.
                    لا رفع ملفات ولا مسارات حرّة من هنا.
                </small>
            </label>

            <?php /*
             * The base product.
             *
             * What a custom order of this cut is sold AS. The 3D editor needs a
             * product to post to the cart -- a cart line points at a variant,
             * and variants belong to products -- so this is the bridge between
             * "the customer chose a cut" and "the shop sold something".
             *
             * Only on an existing type: a product cannot belong to a type that
             * has not been saved yet.
             */ ?>
            <?php if ($editing === null) { ?>
                <p class="field__note">
                    المنتج الأساسي يُختار بعد حفظ النوع وإنشاء منتج عليه.
                    بدونه لا يظهر النوع في محرر الزبون.
                </p>
            <?php } else { ?>
                <label class="field">
                    <span>المنتج الأساسي للطلبات المخصّصة</span>
                    <select name="base_product_id">
                        <option value="0">— بلا منتج أساسي (لا يظهر في المحرر) —</option>
                        <?php foreach ($baseCandidates as $candidate) { ?>
                            <option value="<?= (int) $candidate['id'] ?>"
                                <?= (int) $garment['base_product_id'] === (int) $candidate['id'] ? ' selected' : '' ?>>
                                <?= e($candidate['name']) ?>
                                — <?= (int) $candidate['active_variants'] ?> متغيّر نشط
                                <?= $candidate['artwork_path'] !== null ? ' — مطبوع' : '' ?>
                            </option>
                        <?php } ?>
                    </select>
                    <small>
                        منه يأتي السعر والمخزون واللون والمقاس لكل طلب مخصّص على هذا النوع.
                        القائمة تعرض منتجات هذا النوع النشطة التي لها متغيّر نشط واحد على الأقل.
                        <?php if ($baseCandidates === []) { ?>
                            <br><strong>لا يوجد منتج صالح بعد.</strong>
                            أنشئ منتجًا على هذا النوع وفعّله وأعطه لونًا ومقاسًا.
                        <?php } ?>
                    </small>
                </label>

                <?php if ($garment['base_product_printed']) { ?>
                    <p class="alert alert--notice">
                        المنتج الأساسي المختار <strong>مطبوع</strong> — يحمل عملًا فنيًا خاصًا به.
                        كل طلب مخصّص على هذا النوع سيُباع كهذا المنتج، فقد يجتمع عمل الزبون
                        مع طباعة المنتج نفسه. مسموح، لكن المعتاد أن يكون الأساس قطعة سادة.
                    </p>
                <?php } ?>

                <?php if ($garment['editor_problem'] !== null) { ?>
                    <p class="alert alert--notice">
                        <strong>هذا النوع لا يظهر في المحرر:</strong>
                        <?= e((string) $garment['editor_problem']) ?>
                    </p>
                <?php } ?>
            <?php } ?>

            <div class="grid grid--two">
                <label class="field">
                    <span>عرض مساحة الطباعة (سم)</span>
                    <input type="number" name="print_area_w_cm" min="1" max="200" step="0.1" required
                           value="<?= e((string) $garment['print_area_w_cm']) ?>">
                </label>

                <label class="field">
                    <span>ارتفاع مساحة الطباعة (سم)</span>
                    <input type="number" name="print_area_h_cm" min="1" max="200" step="0.1" required
                           value="<?= e((string) $garment['print_area_h_cm']) ?>">
                </label>
            </div>

            <fieldset>
                <legend>الألوان التي يُقصّ بها هذا النوع</legend>
                <p class="field__note">
                    المنتج يختار من هذه الألوان وحدها. إزالة لون من هنا تمنعه عن كل منتجات النوع.
                </p>
                <div class="options">
                    <?php foreach ($palette as $color) { ?>
                        <label class="option">
                            <input type="checkbox" name="colors[]" value="<?= (int) $color['id'] ?>"
                                <?= in_array((int) $color['id'], $garment['colors'], true) ? 'checked' : '' ?>>
                            <span class="option__swatch" style="background:<?= e($color['hex']) ?>"></span>
                            <span><?= e($color['name']) ?></span>
                        </label>
                    <?php } ?>
                </div>
            </fieldset>

            <fieldset>
                <legend>المقاسات التي يُقصّ بها هذا النوع</legend>
                <p class="field__note">
                    مقاسات القصّة، لا مقاسات المنتج: كل منتج على هذا النوع يُنتَج بهذه المقاسات كلها.
                </p>
                <div class="options">
                    <?php foreach ($sizeChart as $size) { ?>
                        <label class="option">
                            <input type="checkbox" name="sizes[]" value="<?= (int) $size['id'] ?>"
                                <?= in_array((int) $size['id'], $garment['sizes'], true) ? 'checked' : '' ?>>
                            <span><?= e($size['code']) ?> — <?= e($size['label']) ?></span>
                        </label>
                    <?php } ?>
                </div>
            </fieldset>

            <div class="grid grid--two">
                <label class="field">
                    <span>الترتيب</span>
                    <input type="number" name="sort_order" min="0" max="999"
                           value="<?= (int) $garment['sort_order'] ?>">
                </label>

                <label class="field field--check">
                    <input type="checkbox" name="is_active" value="1"
                        <?= (int) $garment['is_active'] ? 'checked' : '' ?>>
                    <span>مفعَّل — يمكن بناء منتجات عليه</span>
                </label>
            </div>

            <button type="submit" class="button button--primary">احفظ النوع</button>
            <?php if ($editing !== null) { ?>
                <a class="button" href="/admin/garments.php">نوع جديد</a>
            <?php } ?>
        </form>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
