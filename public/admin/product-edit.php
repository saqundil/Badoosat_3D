<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\ProductManagementService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\GarmentModels;
use App\Support\Image;
use App\Support\Response;
use App\Support\UploadLimit;

Auth::require();
Csrf::session();

/*
 * The product editor.
 *
 * A product is a garment TYPE, some photographs, optionally one piece of
 * artwork placed on the model, a set of colours drawn from what the type is cut
 * in, a price and a description. That is the whole of it.
 *
 * What used to be here and is not any more: the ready-made design picker, the
 * layer stack, the print placement vocabulary, and the display-mode select.
 * Artwork is one file on the product row now; whether a product is plain or
 * printed is answered by whether that file is there, not by a second field
 * that has to agree with it.
 *
 * Sizes are not on this screen either. A cut is made in the sizes it is made
 * in, so they belong to the type -- which is also why a crafted request cannot
 * add one here.
 */

$service = ProductManagementService::make();
$id = max(0, (int) ($_GET['id'] ?? 0));
$product = $id > 0 ? $service->find($id) : null;

if ($id > 0 && $product === null) {
    Response::redirect('/admin/products.php');
}

$product ??= $service->blank();
$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? null;
unset($_SESSION['errors'], $_SESSION['old']);

if (is_array($old)) {
    $product = array_merge($product, $old);
    $product['id'] = $id;
    $product['base_price_cents'] = isset($old['base_price'])
        ? (int) round((float) $old['base_price'] * 100)
        : (int) $product['base_price_cents'];
    $product['customization_enabled'] = !empty($old['customization_enabled']);
}

$images = $service->images($id);
$primaryImageId = (int) ($old['primary_image_id'] ?? 0);

if ($old === null) {
    foreach ($images as $image) {
        if ((int) ($image['primary_for'] ?? 0) === $id) {
            $primaryImageId = (int) $image['id'];

            break;
        }
    }
}

/*
 * The type decides everything downstream: the model the preview draws, the
 * colours that may be ticked, the sizes the variants are generated across. A
 * shop with no type defined yet cannot publish a product, and the page says so
 * rather than showing an empty select.
 */
$types = $service->garmentTypes();
$garmentId = (int) ($product['garment_id'] ?? 0);

if ($garmentId < 1 || !in_array($garmentId, array_map('intval', array_column($types, 'id')), true)) {
    $garmentId = (int) ($types[0]['id'] ?? 0);
}

$typeRow = $service->garmentType($garmentId);
$typeColors = $service->colorsForGarment($garmentId);
$typeSizes = $service->sizesForGarment($garmentId);

$selectedColors = $old !== null
    ? array_map('intval', (array) ($old['colors'] ?? []))
    : $service->selectedColors($id);

$variantRows = $service->variantRows($id);
$orderLines = $id > 0 ? $service->orderLineCount($id) : 0;

$artworkPath = (string) ($product['artwork_path'] ?? '');

/*
 * The geometry and colour set of every type, so changing the select repaints
 * the preview and the colour list without a round trip. Keyed by type id.
 */
$geometry = $service->garments();
$typeConfig = [];

foreach ($types as $type) {
    $modelKey = (string) $type['model_key'];
    $geometryKey = GarmentModels::has($modelKey) ? GarmentModels::geometryFor($modelKey) : '';

    $typeConfig[(int) $type['id']] = [
        'name' => (string) $type['name_ar'],
        'model' => GarmentModels::pathFor($modelKey),
        'geometry' => $geometryKey,
        'shared_model' => GarmentModels::isShared($modelKey),
        'colors' => array_map(
            static fn (array $color): array => [
                'id' => (int) $color['id'],
                'name' => (string) $color['name'],
                'hex' => (string) $color['hex'],
            ],
            $service->colorsForGarment((int) $type['id'])
        ),
        'sizes' => array_map(
            static fn (array $size): string => (string) $size['code'],
            $service->sizesForGarment((int) $type['id'])
        ),
    ];
}

$title = $id > 0 ? 'تحرير منتج' : 'منتج جديد';
$current = 'products';
$importmap = true;

$body = static function () use (
    $product, $types, $typeRow, $garmentId, $typeColors, $typeSizes, $selectedColors,
    $variantRows, $images, $primaryImageId, $artworkPath, $geometry, $typeConfig,
    $orderLines, $errors, $id
): void {
    ?>
    <main class="admin__body">
        <div class="admin__bar-group" style="justify-content:space-between">
            <h1><?= $id > 0 ? 'تحرير منتج' : 'منتج جديد' ?></h1>
            <a class="button" href="/admin/products.php">رجوع</a>
        </div>

        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <?php if ($types === []) { ?>
            <p class="alert alert--error">
                لا يوجد نوع قطعة مفعَّل. عرّف نوعًا أولًا من
                <a href="/admin/garments.php">شاشة الأنواع</a> — النوع هو ما يحدّد المجسم
                والألوان والمقاسات التي يُبنى عليها المنتج.
            </p>
            <?php return; ?>
        <?php } ?>

        <div class="product-editor">
        <section class="product-model-editor panel" aria-labelledby="model-preview-title">
            <div class="product-model-editor__head">
                <div>
                    <h2 id="model-preview-title">معاينة الموديل</h2>
                    <p class="hint hint--tight">اسحب لتدوير القطعة، وارفع عملًا فنيًا لتجربته مباشرة.</p>
                </div>
                <label class="product-model-editor__color">
                    <span>لون المعاينة</span>
                    <input type="color" value="#F4F0E8" data-model-color>
                </label>
            </div>
            <div class="product-model-editor__stage" data-model-stage>
                <p class="product-model-editor__status" data-model-status>جارٍ تحميل الموديل...</p>
            </div>
            <div class="product-model-editor__views" aria-label="زاوية عرض الموديل">
                <button type="button" class="button" data-camera-preset="front">أمام</button>
                <button type="button" class="button" data-camera-preset="side">جانب</button>
                <button type="button" class="button" data-camera-preset="back">خلف</button>
                <button type="button" class="button" data-camera-preset="reset">إعادة</button>
            </div>
            <p class="hint hint--tight" data-model-shared hidden>
                هذا النوع معروض على مجسم مشترك مؤقتًا — انظر
                <a href="/admin/garments.php">شاشة الأنواع</a>.
            </p>
        </section>

        <form class="panel product-editor__form" id="product-form" method="post"
              action="/admin/product-save.php" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">

            <div class="stat-row">
                <label class="field">
                    <span>الاسم</span>
                    <input type="text" name="name" maxlength="160" required value="<?= e($product['name']) ?>">
                </label>
                <label class="field">
                    <span>المُعرّف</span>
                    <input type="text" name="slug" maxlength="120" required value="<?= e($product['slug']) ?>">
                </label>
            </div>

            <label class="field">
                <span>نوع القطعة</span>
                <select name="garment_id" required data-garment-select>
                    <?php foreach ($types as $type) { ?>
                        <option value="<?= (int) $type['id'] ?>"<?= (int) $type['id'] === $garmentId ? ' selected' : '' ?>>
                            <?= e($type['name_ar']) ?>
                        </option>
                    <?php } ?>
                </select>
                <small>
                    النوع يحدّد المجسم والألوان المتاحة والمقاسات التي تُولَّد بها المتغيّرات.
                    تُعرَّف الأنواع من <a href="/admin/garments.php">شاشة الأنواع</a>.
                </small>
            </label>

            <label class="field">
                <span>الوصف</span>
                <textarea name="description" rows="6"><?= e((string) $product['description']) ?></textarea>
            </label>

            <label class="field">
                <span>السعر الأساسي (دينار)</span>
                <input type="number" name="base_price" min="0" max="1000000" step="0.01" required
                       value="<?= e(number_format((int) $product['base_price_cents'] / 100, 2, '.', '')) ?>">
            </label>

            <fieldset>
                <legend>صور المنتج</legend>

                <label class="field product-images__upload">
                    <span>اسحب الصور هنا أو اختر ملفات</span>
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= UploadLimit::bytes() ?>">
                    <input type="file" name="product_images[]" multiple
                           accept="image/jpeg,image/png,image/webp<?= extension_loaded('imagick') ? ',image/svg+xml' : '' ?>"
                           data-max-bytes="<?= UploadLimit::bytes() ?>">
                    <span class="hint">الحد الفعلي لكل صورة <?= e(UploadLimit::mebibytes(UploadLimit::bytes())) ?> ميغابايت. تُنشأ نسخ WebP بعرض 400 و800 و1200 بكسل وصورة مصغّرة.</span>
                </label>

                <?php if ($images === []) { ?>
                    <p class="hint">لا توجد صور مسجّلة لهذا المنتج. أول صورة مرفوعة تصبح الأساسية.</p>
                <?php } else { ?>
                    <div class="product-images" data-product-images>
                        <?php foreach ($images as $image) {
                            $thumb = Image::thumbnail((string) $image['path']);

                            if (!is_file(Config::get('app.paths.public') . $thumb)) {
                                $thumb = (string) $image['path'];
                            }
                            ?>
                            <article class="product-image" data-product-image>
                                <img src="<?= e($thumb) ?>" alt="" width="160" height="160" loading="lazy">
                                <label class="field field--check">
                                    <input type="radio" name="primary_image_id" value="<?= (int) $image['id'] ?>"
                                           <?= (int) $image['id'] === $primaryImageId ? ' checked' : '' ?>>
                                    <span>الصورة الأساسية</span>
                                </label>
                                <label class="field">
                                    <span>النوع</span>
                                    <input type="text" name="image_type[<?= (int) $image['id'] ?>]" maxlength="40"
                                           value="<?= e($image['image_type']) ?>" pattern="[a-z0-9_\-]+">
                                </label>
                                <label class="field">
                                    <span>الترتيب</span>
                                    <input type="number" name="sort_order[<?= (int) $image['id'] ?>]" min="0" max="65535"
                                           value="<?= (int) $image['sort_order'] ?>" data-image-order>
                                </label>
                                <div class="field__row">
                                    <button class="button" type="button" data-image-move="up" aria-label="تحريك الصورة لأعلى">↑</button>
                                    <button class="button" type="button" data-image-move="down" aria-label="تحريك الصورة لأسفل">↓</button>
                                </div>
                                <label class="field field--check">
                                    <input type="checkbox" name="delete_image[<?= (int) $image['id'] ?>]" value="1">
                                    <span>حذف عند الحفظ</span>
                                </label>
                            </article>
                        <?php } ?>
                    </div>
                <?php } ?>
            </fieldset>

            <fieldset>
                <legend>العمل الفني</legend>

                <p class="hint">
                    ملف واحد يُطبع على القطعة. اتركه فارغًا لتكون القطعة سادة —
                    <strong>وجود العمل الفني هو ما يجعل المنتج مطبوعًا، ولا يوجد حقل نوع منفصل.</strong>
                </p>

                <label class="field product-artwork__drop">
                    <span>ارفع العمل الفني</span>
                    <input type="file" name="artwork" data-artwork-input
                           accept="image/jpeg,image/png,image/webp,image/svg+xml">
                    <span class="hint">PNG أو JPEG أو WebP أو SVG، بحد أقصى <?= e(UploadLimit::mebibytes(UploadLimit::bytes())) ?> ميغابايت.</span>
                </label>

                <?php if ($artworkPath !== '') { ?>
                    <div class="product-artwork__current">
                        <img src="<?= e($artworkPath) ?>" alt="العمل الفني الحالي"
                             width="120" height="120" loading="lazy">
                        <label class="field field--check">
                            <input type="checkbox" name="remove_artwork" value="1" data-artwork-remove>
                            <span>احذف العمل الفني واجعل المنتج سادة</span>
                        </label>
                    </div>
                <?php } ?>

                <div class="product-placement" data-artwork-placement>
                    <div class="product-placement__head">
                        <strong>موضع العمل الفني وحجمه</strong>
                        <button type="button" class="button" data-placement-reset>إعادة للمنتصف</button>
                    </div>

                    <label class="product-placement__control">
                        <span>الوجه</span>
                        <select name="artwork_side" data-artwork-side>
                            <option value="front"<?= ($product['artwork_side'] ?? 'front') === 'front' ? ' selected' : '' ?>>الأمام</option>
                            <option value="back"<?= ($product['artwork_side'] ?? 'front') === 'back' ? ' selected' : '' ?>>الخلف</option>
                        </select>
                    </label>

                    <label class="product-placement__control">
                        <span>أفقي</span>
                        <input type="range" name="artwork_x" min="0" max="100" step="1"
                               value="<?= (int) ($product['artwork_x'] ?? 50) ?>" data-placement="x">
                        <output data-placement-output="x"><?= (int) ($product['artwork_x'] ?? 50) ?></output>
                    </label>

                    <label class="product-placement__control">
                        <span>عمودي</span>
                        <input type="range" name="artwork_y" min="0" max="100" step="1"
                               value="<?= (int) ($product['artwork_y'] ?? 50) ?>" data-placement="y">
                        <output data-placement-output="y"><?= (int) ($product['artwork_y'] ?? 50) ?></output>
                    </label>

                    <label class="product-placement__control">
                        <span>الحجم</span>
                        <input type="range" name="artwork_scale" min="10" max="150" step="1"
                               value="<?= (int) ($product['artwork_scale'] ?? 100) ?>" data-placement="scale">
                        <output data-placement-output="scale"><?= (int) ($product['artwork_scale'] ?? 100) ?>%</output>
                    </label>
                </div>
            </fieldset>

            <?php /*
             * Colours, and only colours.
             *
             * INSIDE the form, which the previous version of this page was not:
             * the colour and size fieldset sat after </form>, so a browser
             * submitted none of it and only a hand-built request ever carried
             * the ticks. The variant tests passed throughout because they post
             * directly.
             */ ?>
            <fieldset>
                <legend>ألوان هذا المنتج</legend>

                <p class="hint">
                    من ألوان النوع وحدها. كل لون مختار يُضرب في مقاسات النوع لينتج متغيّرًا
                    قابلًا للبيع، يبدأ بسعر المنتج الأساسي ومخزون صفر.
                </p>
                <p class="hint">
                    <strong>تغيير السعر الأساسي لا يغيّر أسعار المتغيّرات القائمة.</strong>
                    أسعار المتغيّرات ومخزونها تُعدَّل من صفحة الكتالوج، والحفظ هنا لا يدهسها.
                </p>

                <div class="options-grid" data-variant-colors>
                    <?php foreach ($typeColors as $color) { ?>
                        <label class="field field--check">
                            <input type="checkbox" name="colors[]" value="<?= (int) $color['id'] ?>"
                                   data-variant-option
                                   <?= in_array((int) $color['id'], $selectedColors, true) ? 'checked' : '' ?>>
                            <span class="slide__swatch" style="background:<?= e($color['hex']) ?>"></span>
                            <span><?= e($color['name']) ?></span>
                        </label>
                    <?php } ?>
                </div>

                <p class="hint">
                    مقاسات النوع:
                    <strong data-type-sizes><?= e(implode('، ', array_column($typeSizes, 'code'))) ?: 'لا مقاسات' ?></strong>
                    — تُعرَّف من <a href="/admin/garments.php">شاشة الأنواع</a>، لا من هنا.
                </p>

                <p class="hint" data-variant-count aria-live="polite"></p>
            </fieldset>

            <label class="field field--check">
                <input type="checkbox" name="customization_enabled" value="1" data-customization<?= !empty($product['customization_enabled']) ? ' checked' : '' ?>>
                <span>السماح بالتخصيص عند دعم القطعة له</span>
            </label>

            <button type="submit" class="button button--primary">حفظ</button>
        </form>
        </div>

        <?php if ($variantRows !== []) { ?>
            <?php /* Read-only on purpose: price and stock belong to the
                     catalogue page, and two editors for one number is how they
                     end up disagreeing. */ ?>
            <div class="panel" style="margin-top:18px">
                <h2>المتغيّرات</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>اللون</th><th>المقاس</th><th>SKU</th>
                            <th>السعر</th><th>المخزون</th><th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($variantRows as $variant) { ?>
                            <tr>
                                <td>
                                    <span class="slide__swatch" style="background:<?= e($variant['color_hex']) ?>"></span>
                                    <?= e($variant['color_name']) ?>
                                </td>
                                <td><?= e($variant['size_code']) ?></td>
                                <td><?= e($variant['sku']) ?></td>
                                <td><?= e(money((int) $variant['price_cents'])) ?></td>
                                <td><?= (int) $variant['stock'] ?></td>
                                <td>
                                    <?php if ((int) $variant['is_active'] === 1) { ?>
                                        نشط
                                    <?php } else { ?>
                                        معطّل<?= (int) $variant['deactivated_by_product'] === 1
                                            ? ' (بتعطيل المنتج)' : '' ?>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <p class="hint">لتعديل الأسعار والمخزون: صفحة الكتالوج.</p>
            </div>
        <?php } ?>

        <?php if ($id > 0) { ?>
            <?php
            /*
             * Two ways to take a product off the shop, and they are not the
             * same thing. Deactivating hides it and keeps every order that ever
             * contained it intact; deleting removes it for good and is refused
             * outright once a single order line depends on it.
             */
            $isActive = (int) ($product['is_active'] ?? 1) === 1;
            ?>
            <div class="panel" style="margin-top:18px">
                <h2>إتاحة المنتج</h2>

                <form method="post" action="/admin/product-save.php">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <p class="hint">
                        <?= $isActive
                            ? 'المنتج ظاهر في المتجر الآن. تعطيله يخفيه عن الكتالوج وصفحة المنتج ويمنع إضافته إلى السلة، ولا يمسّ الطلبات السابقة.'
                            : 'المنتج معطّل ولا يظهر في المتجر. إعادة تفعيله تعيده وتعيد متغيّراته.' ?>
                    </p>
                    <button type="submit" class="button<?= $isActive ? '' : ' button--primary' ?>">
                        <?= $isActive ? 'تعطيل المنتج' : 'إعادة التفعيل' ?>
                    </button>
                </form>
            </div>

            <form class="panel" method="post" action="/admin/product-save.php" style="margin-top:18px">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $id ?>">
                <h2>حذف المنتج</h2>
                <?php if ($orderLines > 0) { ?>
                    <p class="hint">
                        لا يمكن حذف هذا المنتج: يظهر في <?= (int) $orderLines ?> سطر طلب.
                        استخدم التعطيل أعلاه.
                    </p>
                    <button type="submit" class="button" disabled>حذف</button>
                <?php } else { ?>
                    <p class="hint">يحذف المنتج وصفوفه التابعة نهائيًا. لا طلبات مرتبطة به.</p>
                    <button type="submit" class="button">حذف</button>
                <?php } ?>
            </form>
        <?php } ?>
    </main>

    <script type="application/json" id="product-editor-config"><?= json_island([
        'geometry' => $geometry,
        'types' => $typeConfig,
        'artwork' => [
            'path' => $artworkPath !== '' ? $artworkPath : null,
            'side' => (string) ($product['artwork_side'] ?? 'front'),
            'x' => (int) ($product['artwork_x'] ?? 50),
            'y' => (int) ($product['artwork_y'] ?? 50),
            'scale' => (int) ($product['artwork_scale'] ?? 100),
        ],
    ]) ?></script>

    <script>
    (() => {
        const grid = document.querySelector('[data-product-images]');
        if (!grid) return;

        grid.addEventListener('click', (event) => {
            const button = event.target.closest('[data-image-move]');
            if (!button) return;

            const card = button.closest('[data-product-image]');
            const sibling = button.dataset.imageMove === 'up'
                ? card.previousElementSibling
                : card.nextElementSibling;
            if (!sibling) return;

            if (button.dataset.imageMove === 'up') {
                grid.insertBefore(card, sibling);
            } else {
                grid.insertBefore(sibling, card);
            }

            [...grid.querySelectorAll('[data-image-order]')].forEach((input, index) => {
                input.value = index;
            });
        });
    })();
    </script>
    <script type="module" src="/assets/js/admin-product-artwork.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
