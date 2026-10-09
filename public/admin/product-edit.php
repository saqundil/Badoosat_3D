<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\ProductManagementService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\GarmentModels;
use App\Support\Image;
use App\Support\Lang;
use App\Support\Response;
use App\Support\UploadLimit;

Auth::require();
Csrf::session();

/*
 * The product editor (template D, the studio).
 *
 * A product is a garment TYPE, some photographs, optionally one piece of
 * artwork placed on the model, a set of colours drawn from what the type is cut
 * in, a price and a description. That is the whole of it.
 *
 * Sections on one side, the 3D preview in the middle, the section's fields on
 * the other, and one save bar for the lot. Activating, deactivating and
 * deleting are their own small forms, each behind a confirmation, so nothing
 * on the page is a second "Save".
 *
 * Whether a product is plain or printed is answered by whether its artwork file
 * is there, not by a second field that has to agree with it. Sizes belong to
 * the type -- which is also why a crafted request cannot add one here.
 */

$service = ProductManagementService::make();
$id = max(0, (int) ($_GET['id'] ?? 0));
$stored = $id > 0 ? $service->find($id) : null;

if ($id > 0 && $stored === null) {
    Response::redirect('/admin/products.php');
}

$product = $stored ?? $service->blank();
$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? null;
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['errors'], $_SESSION['old'], $_SESSION['notice']);

if (is_array($old)) {
    $product = array_merge($product, $old);
    $product['id'] = $id;
    $product['base_price_cents'] = isset($old['base_price']) && is_numeric($old['base_price'])
        ? (int) round((float) $old['base_price'] * 100)
        : (int) ($stored['base_price_cents'] ?? 0);
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

$public = (string) Config::get('app.paths.public');
$exists = static fn (?string $path): bool => $path !== null && $path !== '' && !str_contains($path, '..') && is_file($public . $path);

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

$locale = Lang::locale();
$typeName = static fn (array $t): string => (string) ($locale === 'en' && trim((string) ($t['name_en'] ?? '')) !== '' ? $t['name_en'] : $t['name_ar']);

$typeColors = $service->colorsForGarment($garmentId);
$typeSizes = $service->sizesForGarment($garmentId);

$selectedColors = $old !== null
    ? array_map('intval', (array) ($old['colors'] ?? []))
    : $service->selectedColors($id);

$variantRows = $service->variantRows($id);
$orderLines = $id > 0 ? $service->orderLineCount($id) : 0;
$artworkPath = (string) ($product['artwork_path'] ?? '');
$isActive = $stored === null || (int) ($stored['is_active'] ?? 1) === 1;

/* ---------------------------------------------------- ready to sell? */

$activeVariants = array_values(array_filter($variantRows, static fn (array $v): bool => (int) $v['is_active'] === 1));
$stockTotal = array_sum(array_map(static fn (array $v): int => (int) $v['stock'], $activeVariants));
$photoCount = count(array_filter($images, static fn (array $i): bool => $exists((string) $i['path'])));
$storedPrice = (int) ($stored['base_price_cents'] ?? 0);

$checks = [
    ['key' => 'active', 'ok' => $stored !== null && $isActive, 'section' => 'selling'],
    ['key' => 'price', 'ok' => $storedPrice > 0, 'section' => 'product'],
    ['key' => 'colours', 'ok' => $activeVariants !== [], 'section' => 'product'],
    ['key' => 'stock', 'ok' => $stockTotal > 0, 'section' => 'selling'],
    ['key' => 'photo', 'ok' => $photoCount > 0, 'section' => 'photos'],
];
$sellable = $stored !== null && $isActive && $storedPrice > 0 && $stockTotal > 0;

/*
 * The geometry and colour set of every type, so changing the select repaints
 * the preview and the colour list without a round trip. Keyed by type id.
 */
$geometry = $service->garments();
$typeConfig = [];

foreach ($types as $type) {
    $modelKey = (string) $type['model_key'];

    $typeConfig[(int) $type['id']] = [
        'name' => $typeName($type),
        'model' => GarmentModels::pathFor($modelKey),
        'geometry' => GarmentModels::has($modelKey) ? GarmentModels::geometryFor($modelKey) : '',
        'shared_model' => GarmentModels::isShared($modelKey),
        'colors' => array_map(
            static fn (array $color): array => ['id' => (int) $color['id'], 'name' => (string) $color['name'], 'hex' => (string) $color['hex']],
            $service->colorsForGarment((int) $type['id'])
        ),
        'sizes' => array_map(static fn (array $size): string => (string) $size['code'], $service->sizesForGarment((int) $type['id'])),
    ];
}

/* The 3D preview's fallback picture: the main photo, else the first that exists. */
$posterPath = null;
foreach ($images as $image) {
    if ($exists((string) $image['path']) && ($posterPath === null || (int) $image['id'] === $primaryImageId)) {
        $posterPath = (string) $image['path'];
    }
}

$name = trim((string) $product['name']);
// Isolated: a name in the other script would otherwise take the quotes with it.
$title = $stored === null ? Lang::get('admin.product.new_title')
    : Lang::get('admin.product.edit_title', ['name' => "\u{2068}" . ($name !== '' ? $name : (string) $stored['name']) . "\u{2069}"]);
$current = 'products';
$importmap = true;
$toast = $notice !== null ? ['text' => $notice] : null;

$body = static function () use (
    $product, $stored, $types, $garmentId, $typeName, $typeColors, $typeSizes, $selectedColors,
    $variantRows, $activeVariants, $stockTotal, $images, $primaryImageId, $artworkPath, $geometry, $typeConfig,
    $orderLines, $errors, $id, $exists, $checks, $sellable, $isActive, $posterPath, $title
): void {
    $err = static fn (string $field): ?string => $errors[$field] ?? null;
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'back'     => '/admin/products.php',
            'title'    => $title,
            'subtitle' => Lang::get($stored === null ? 'admin.product.new_subtitle' : 'admin.product.edit_subtitle'),
            'actions'  => $stored === null ? null : static function () use ($stored, $sellable, $isActive): void {
                admin_partial('status-chip', [
                    'label' => Lang::get('admin.product.state.' . (!$isActive ? 'inactive' : ($sellable ? 'on_sale' : 'not_selling'))),
                    'tone'  => !$isActive ? 'done' : ($sellable ? 'active' : 'stopped'),
                ]); ?>
                <a class="button button--secondary" href="/product.php?slug=<?= e(rawurlencode((string) $stored['slug'])) ?>" target="_blank" rel="noopener">
                    <?= admin_icon('external') ?><?= e(Lang::get('admin.products.view')) ?>
                </a>
            <?php },
        ]); ?>

        <?php if ($types === []) {
            admin_partial('state', [
                'kind'   => 'empty',
                'text'   => Lang::get('admin.product.no_types'),
                'hint'   => Lang::get('admin.product.no_types_hint'),
                'action' => ['href' => '/admin/garments.php?new=1', 'text' => Lang::get('admin.garments.new')],
            ]); ?>
            </main>
            <?php return;
        } ?>

        <?php admin_partial('form/error-summary', [
            'errors' => $errors,
            'ids'    => ['colors' => 'f-colors', 'product_images' => 'f-product-images', 'artwork' => 'f-artwork',
                         'primary_image_id' => 'f-product-images', 'delete' => 'product-delete-open'],
        ]); ?>

        <form id="product-form" method="post" action="/admin/product-save.php" enctype="multipart/form-data" data-watch>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= UploadLimit::bytes() ?>">

            <?php admin_partial('studio', [
                'id'     => 'product-studio',
                'label'  => Lang::get('admin.product.studio'),
                'layersTitle' => Lang::get('admin.product.sections'),
                'layers' => static function () use ($images, $artworkPath, $selectedColors, $stored): void {
                    $rows = [
                        'product' => ['shirt', Format::plural('admin.garments.colour_count', count($selectedColors))],
                        'photos'  => ['grid', Format::plural('admin.product.photo_count', count($images))],
                        'artwork' => ['file', Lang::get($artworkPath !== '' ? 'admin.product.artwork_set' : 'admin.product.artwork_none')],
                        'selling' => ['store', Lang::get($stored === null ? 'admin.product.after_save' : 'admin.product.selling_hint')],
                    ];
                    $first = true;
                    foreach ($rows as $key => [$icon, $hint]) { ?>
                        <button type="button" class="layer-row<?= $first ? ' is-selected' : '' ?>" role="option" aria-selected="<?= $first ? 'true' : 'false' ?>" data-studio-item="<?= e($key) ?>">
                            <span class="layer-row__icon"><?= admin_icon($icon) ?></span>
                            <span class="layer-row__text">
                                <span><?= e(Lang::get('admin.product.tab.' . $key)) ?></span>
                                <span class="layer-row__hint"><?= e($hint) ?></span>
                            </span>
                        </button>
                    <?php $first = false;
                    }
                },
                'layersFoot' => static function () use ($checks, $sellable, $stored): void { ?>
                    <section class="readiness" aria-labelledby="readiness-title">
                        <h3 class="readiness__title" id="readiness-title"><?= e(Lang::get('admin.product.ready.title')) ?></h3>
                        <p class="readiness__verdict"><?= e(Lang::get($stored === null ? 'admin.product.ready.new' : ($sellable ? 'admin.product.ready.yes' : 'admin.product.ready.no'))) ?></p>
                        <ul class="readiness__list">
                            <?php foreach ($checks as $check) { ?>
                                <li class="readiness__item<?= $check['ok'] ? ' is-ok' : '' ?>">
                                    <span class="readiness__mark" aria-hidden="true"><?= admin_icon($check['ok'] ? 'check' : 'dot') ?></span>
                                    <span class="visually-hidden"><?= e(Lang::get($check['ok'] ? 'admin.product.ready.done' : 'admin.product.ready.todo')) ?>:</span>
                                    <button type="button" class="readiness__link" data-studio-item="<?= e($check['section']) ?>"><?= e(Lang::get('admin.product.ready.' . $check['key'])) ?></button>
                                </li>
                            <?php } ?>
                        </ul>
                    </section>
                <?php },
                'tools' => static function () use ($typeColors): void { ?>
                    <div class="preview-tools" data-needs-webgl>
                    <div class="segmented segmented--small" role="group" aria-label="<?= e(Lang::get('admin.product.view_label')) ?>">
                        <?php foreach (['front', 'back', 'side'] as $view) { ?>
                            <button type="button" class="segmented__option" data-camera-preset="<?= $view ?>" aria-pressed="<?= $view === 'front' ? 'true' : 'false' ?>"><?= e(Lang::get('admin.product.view.' . $view)) ?></button>
                        <?php } ?>
                    </div>
                    <button type="button" class="icon-button icon-button--quiet" data-camera-preset="reset"
                            aria-label="<?= e(Lang::get('admin.product.view.reset')) ?>" title="<?= e(Lang::get('admin.product.view.reset')) ?>"><?= admin_icon('undo') ?></button>
                    <div class="preview-colours" role="radiogroup" aria-label="<?= e(Lang::get('admin.product.preview_colour')) ?>" data-model-colors>
                        <?php foreach ($typeColors as $i => $color) { ?>
                            <label class="preview-colours__item" title="<?= e((string) $color['name']) ?>">
                                <input type="radio" name="preview_colour" value="<?= e((string) $color['hex']) ?>" form="product-preview-only"<?= $i === 0 ? ' checked' : '' ?>>
                                <span class="swatch swatch--on-light" style="--swatch: <?= e((string) $color['hex']) ?>" aria-hidden="true"></span>
                                <span class="visually-hidden"><?= e((string) $color['name']) ?></span>
                            </label>
                        <?php } ?>
                    </div>
                    </div>
                <?php },
                'canvas' => static function () use ($posterPath, $artworkPath): void { ?>
                    <div class="product-stage" data-model-stage
                         data-msg-loading="<?= e(Lang::get('admin.product.preview.loading')) ?>"
                         data-msg-failed="<?= e(Lang::get('admin.product.preview.failed')) ?>"
                         data-msg-no-model="<?= e(Lang::get('admin.product.preview.no_model')) ?>"
                         data-msg-canvas="<?= e(Lang::get('admin.product.preview.canvas')) ?>">
                        <?php /* What phones see, and what everyone sees until WebGL is up:
                                 three.js is never fetched under 768px or on touch. */ ?>
                        <div class="product-stage__poster" data-model-poster>
                            <?php if ($posterPath !== null || $artworkPath !== '') { ?>
                                <img src="<?= e($posterPath ?? $artworkPath) ?>" alt="" width="600" height="600" decoding="async">
                            <?php } else { ?>
                                <span class="product-stage__empty"><?= admin_icon('shirt') ?></span>
                            <?php } ?>
                            <p class="product-stage__note" data-model-note><?= e(Lang::get('admin.product.preview.phone')) ?></p>
                        </div>
                        <p class="product-stage__status" data-model-status hidden></p>
                    </div>
                <?php },
                'panels' => [
                    'product' => ['label' => Lang::get('admin.product.tab.product'), 'body' => static function () use (
                        $product, $types, $garmentId, $typeName, $typeColors, $typeSizes, $selectedColors, $err
                    ): void { ?>
                        <?php admin_partial('form/field', [
                            'type' => 'text', 'name' => 'name', 'label' => Lang::get('admin.product.f.name'), 'value' => (string) $product['name'],
                            'required' => true, 'maxlength' => 160, 'dir' => 'auto', 'invalid' => Lang::get('admin.form.required'), 'error' => $err('name'),
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'text', 'name' => 'slug', 'label' => Lang::get('admin.product.f.slug'), 'value' => (string) $product['slug'],
                            'required' => true, 'maxlength' => 120, 'dir' => 'ltr', 'help' => Lang::get('admin.product.slug_help'),
                            'invalid' => Lang::get('admin.product.err.slug_format'), 'error' => $err('slug'),
                            'attrs' => ['pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'autocomplete' => 'off', 'translate' => 'no'],
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'select', 'name' => 'garment_id', 'label' => Lang::get('admin.product.f.garment_id'), 'value' => (string) $garmentId,
                            'required' => true, 'error' => $err('garment_id'), 'help' => Lang::get('admin.product.type_help'),
                            'attrs' => ['data-garment-select' => true],
                            'options' => array_map(static fn (array $t): array => ['value' => (int) $t['id'], 'label' => $typeName($t)], $types),
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'money', 'name' => 'base_price', 'label' => Lang::get('admin.product.f.base_price'),
                            // A new product starts unpriced, not at 0.00: the field asks to be filled.
                            'value' => (int) $product['base_price_cents'] > 0 ? number_format((int) $product['base_price_cents'] / 100, 2, '.', '') : '',
                            'required' => true, 'help' => Lang::get('admin.product.price_help'),
                            'invalid' => Lang::get('admin.product.err.price'), 'error' => $err('base_price'),
                            'attrs' => ['placeholder' => '0.00'],
                        ]); ?>
                        <div data-field class="form-field form-field--checklist<?= $err('colors') !== null ? ' form-field--error' : '' ?>">
                            <fieldset class="form-field__group" aria-describedby="product-colours-help">
                                <legend class="form-field__label"><?= e(Lang::get('admin.product.f.colors')) ?></legend>
                                <div class="checklist" id="f-colors" tabindex="-1" data-variant-colors
                                     data-label-sizes-none="<?= e(Lang::get('admin.product.sizes_none')) ?>">
                                    <?php foreach ($typeColors as $color) { ?>
                                        <label class="check">
                                            <input type="checkbox" name="colors[]" value="<?= (int) $color['id'] ?>" data-variant-option
                                                <?= in_array((int) $color['id'], $selectedColors, true) ? ' checked' : '' ?>>
                                            <span class="check__box" aria-hidden="true"></span>
                                            <span class="swatch swatch--on-light" style="--swatch: <?= e((string) $color['hex']) ?>" aria-hidden="true"></span>
                                            <span class="check__text"><bdi><?= e((string) $color['name']) ?></bdi></span>
                                        </label>
                                    <?php } ?>
                                </div>
                            </fieldset>
                            <p class="form-field__help" id="product-colours-help">
                                <?= e(Lang::get('admin.product.colours_help')) ?>
                                <?= e(Lang::get('admin.product.sizes_label')) ?>
                                <bdi translate="no" data-type-sizes><?= e(implode(' · ', array_column($typeSizes, 'code')) ?: Lang::get('admin.product.sizes_none')) ?></bdi>
                            </p>
                            <p class="form-field__help variant-count" aria-live="polite" data-variant-count
                               data-msg-none="<?= e(Lang::get('admin.product.variants_none')) ?>"
                               data-msg-count="<?= e(Lang::get('admin.product.variants_count')) ?>"></p>
                            <?php if ($err('colors') !== null) { ?>
                                <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e((string) $err('colors')) ?></span></p>
                            <?php } ?>
                        </div>
                        <?php admin_partial('form/field', [
                            'type' => 'toggle', 'name' => 'customization_enabled', 'label' => Lang::get('admin.product.f.customization_enabled'),
                            'value' => !empty($product['customization_enabled']), 'help' => Lang::get('admin.product.custom_help'),
                            'attrs' => ['data-customization' => true],
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'textarea', 'name' => 'description', 'label' => Lang::get('admin.product.f.description'),
                            'value' => (string) $product['description'], 'dir' => 'auto', 'attrs' => ['rows' => 5],
                        ]); ?>
                    <?php }],

                    'photos' => ['label' => Lang::get('admin.product.tab.photos'), 'body' => static function () use ($images, $primaryImageId, $exists, $err): void {
                        admin_partial('form/dropzone', [
                            'name' => 'product_images', 'id' => 'f-product-images', 'label' => Lang::get('admin.product.f.product_images'),
                            'multiple' => true, 'ratio' => '16 / 9', 'error' => $err('product_images') ?? $err('primary_image_id'),
                            'accept' => 'image/jpeg,image/png,image/webp' . (extension_loaded('imagick') ? ',image/svg+xml' : ''),
                            'help' => Lang::get('admin.product.photos_help', ['mb' => UploadLimit::mebibytes(UploadLimit::bytes())]),
                        ]); ?>
                        <?php if ($images === []) { ?>
                            <p class="form-field__help"><?= e(Lang::get('admin.product.photos_none')) ?></p>
                        <?php } else { ?>
                            <fieldset class="form-field__group">
                                <legend class="form-field__label"><?= e(Lang::get('admin.product.photos_current')) ?></legend>
                                <ul class="photo-list" data-product-images>
                                    <?php foreach ($images as $index => $image) {
                                        $imageId = (int) $image['id'];
                                        $path = (string) $image['path'];
                                        $thumb = Image::thumbnail($path);
                                        $thumb = $exists($thumb) ? $thumb : ($exists($path) ? $path : null);
                                        $label = Lang::get('admin.product.photo_n', ['n' => Format::number($index + 1)]); ?>
                                        <li class="photo-card<?= $thumb === null ? ' photo-card--missing' : '' ?>" data-product-image>
                                            <?php if ($thumb !== null) { ?>
                                                <img class="photo-card__img" src="<?= e($thumb) ?>" alt="<?= e($label) ?>" width="160" height="160" loading="lazy" decoding="async">
                                            <?php } else { ?>
                                                <span class="photo-card__img photo-card__img--missing" role="img" aria-label="<?= e($label . ' — ' . Lang::get('admin.product.photo_missing')) ?>">
                                                    <?= admin_icon('alert') ?><span><?= e(Lang::get('admin.product.photo_missing')) ?></span>
                                                </span>
                                            <?php } ?>
                                            <input type="hidden" name="image_type[<?= $imageId ?>]" value="<?= e((string) $image['image_type']) ?>">
                                            <input type="hidden" name="sort_order[<?= $imageId ?>]" value="<?= (int) $image['sort_order'] ?>" data-initial="<?= (int) $image['sort_order'] ?>" data-image-order>
                                            <label class="check check--small">
                                                <input type="radio" name="primary_image_id" value="<?= $imageId ?>"<?= $imageId === $primaryImageId ? ' checked' : '' ?>>
                                                <span class="check__box check__box--radio" aria-hidden="true"></span>
                                                <span class="check__text"><?= e(Lang::get('admin.product.photo_primary')) ?></span>
                                            </label>
                                            <div class="photo-card__tools">
                                                <button type="button" class="icon-button icon-button--quiet" data-image-move="up"
                                                        aria-label="<?= e(Lang::get('admin.product.photo_earlier', ['n' => $label])) ?>" title="<?= e(Lang::get('admin.product.photo_earlier', ['n' => $label])) ?>"><?= admin_icon('back') ?></button>
                                                <button type="button" class="icon-button icon-button--quiet" data-image-move="down"
                                                        aria-label="<?= e(Lang::get('admin.product.photo_later', ['n' => $label])) ?>" title="<?= e(Lang::get('admin.product.photo_later', ['n' => $label])) ?>"><?= admin_icon('forward') ?></button>
                                                <label class="check check--small check--danger">
                                                    <input type="checkbox" name="delete_image[<?= $imageId ?>]" value="1">
                                                    <span class="check__box" aria-hidden="true"></span>
                                                    <span class="check__text"><?= e(Lang::get('admin.product.photo_delete')) ?></span>
                                                </label>
                                            </div>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </fieldset>
                        <?php }
                    }],

                    'artwork' => ['label' => Lang::get('admin.product.tab.artwork'), 'body' => static function () use ($product, $artworkPath, $err): void {
                        admin_partial('form/dropzone', [
                            'name' => 'artwork', 'id' => 'f-artwork', 'label' => Lang::get('admin.product.f.artwork'),
                            'current' => $artworkPath !== '' ? $artworkPath : null, 'removeName' => 'remove_artwork', 'ratio' => '1 / 1',
                            'accept' => 'image/jpeg,image/png,image/webp,image/svg+xml', 'error' => $err('artwork'),
                            'help' => Lang::get('admin.product.artwork_help', ['mb' => UploadLimit::mebibytes(UploadLimit::bytes())]),
                            'inputAttrs' => ['data-artwork-input' => true], 'removeAttrs' => ['data-artwork-remove' => true],
                        ]); ?>
                        <fieldset class="form-field__group placement" data-artwork-placement>
                            <legend class="form-field__label"><?= e(Lang::get('admin.product.placement')) ?></legend>
                            <?php admin_partial('form/field', [
                                'type' => 'segmented', 'name' => 'artwork_side', 'label' => Lang::get('admin.product.f.artwork_side'),
                                'value' => (string) ($product['artwork_side'] ?? 'front'), 'error' => $err('artwork_side'),
                                'options' => [['value' => 'front', 'label' => Lang::get('admin.side.front')], ['value' => 'back', 'label' => Lang::get('admin.side.back')]],
                            ]); ?>
                            <?php foreach (['artwork_x' => ['x', 0, 100, 50], 'artwork_y' => ['y', 0, 100, 50], 'artwork_scale' => ['scale', 10, 150, 100]] as $field => [$key, $min, $max, $default]) {
                                $value = (int) ($product[$field] ?? $default); ?>
                                <div class="form-field form-field--range">
                                    <label class="form-field__label" for="f-<?= $field ?>"><?= e(Lang::get('admin.product.f.' . $field)) ?></label>
                                    <div class="range">
                                        <input class="range__input" type="range" id="f-<?= $field ?>" name="<?= $field ?>" min="<?= $min ?>" max="<?= $max ?>" step="1"
                                               value="<?= $value ?>" data-placement="<?= $key ?>">
                                        <output class="range__value" for="f-<?= $field ?>" data-placement-output="<?= $key ?>"><?= e(Format::number($value)) ?>%</output>
                                    </div>
                                    <?php if ($err($field) !== null) { ?><p class="form-field__error"><?= admin_icon('alert') ?><span><?= e((string) $err($field)) ?></span></p><?php } ?>
                                </div>
                            <?php } ?>
                            <button type="button" class="button button--secondary button--small" data-placement-reset><?= e(Lang::get('admin.product.placement_reset')) ?></button>
                        </fieldset>
                        <p class="form-field__help"><?= e(Lang::get('admin.product.artwork_plain')) ?></p>
                    <?php }],

                    'selling' => ['label' => Lang::get('admin.product.tab.selling'), 'body' => static function () use (
                        $stored, $variantRows, $activeVariants, $stockTotal, $orderLines, $isActive
                    ): void {
                        if ($stored === null) { ?>
                            <p class="form-field__help"><?= e(Lang::get('admin.product.selling_after_save')) ?></p>
                            <?php return;
                        }
                        // One line per colour: its sizes, its stock, how many are on sale.
                        $byColour = [];
                        foreach ($variantRows as $v) {
                            $c = &$byColour[(int) $v['color_id']];
                            $c ??= ['name' => (string) $v['color_name'], 'hex' => (string) $v['color_hex'], 'sizes' => 0, 'stock' => 0, 'off' => 0];
                            if ((int) $v['is_active'] === 1) {
                                $c['sizes']++;
                                $c['stock'] += (int) $v['stock'];
                            } else {
                                $c['off']++;
                            }
                            unset($c);
                        } ?>
                        <section class="panel-block" aria-labelledby="variants-title">
                            <h3 class="panel-block__title" id="variants-title"><?= e(Lang::get('admin.product.variants_title')) ?></h3>
                            <p class="form-field__help"><?= e(Lang::get('admin.product.variants_summary', [
                                'variants' => Format::plural('admin.products.variant_count', count($activeVariants)),
                                'stock' => Format::number($stockTotal),
                            ])) ?></p>
                            <?php if ($byColour !== []) { ?>
                                <ul class="variant-summary">
                                    <?php foreach ($byColour as $c) { ?>
                                        <li>
                                            <span class="swatch swatch--on-light" style="--swatch: <?= e($c['hex']) ?>" aria-hidden="true"></span>
                                            <bdi class="variant-summary__name"><?= e($c['name']) ?></bdi>
                                            <span class="variant-summary__meta"><?= e(Lang::get('admin.product.colour_line', [
                                                'sizes' => Format::number($c['sizes']), 'stock' => Format::number($c['stock']),
                                            ])) ?><?= $c['off'] > 0 ? e(Lang::get('admin.list_sep') . Lang::get('admin.product.colour_off', ['n' => Format::number($c['off'])])) : '' ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                            <a class="button button--secondary button--small" href="/admin/catalog.php"><?= e(Lang::get('admin.product.open_stock')) ?></a>
                        </section>

                        <section class="panel-block" aria-labelledby="availability-title">
                            <h3 class="panel-block__title" id="availability-title"><?= e(Lang::get('admin.product.availability')) ?></h3>
                            <p class="form-field__help"><?= e(Lang::get($isActive ? 'admin.product.active_text' : 'admin.product.inactive_text')) ?></p>
                            <button type="submit" class="button <?= $isActive ? 'button--secondary' : 'button--primary' ?>" form="product-availability"
                                    <?= $isActive ? 'data-dialog-open="product-deactivate-dialog"' : '' ?>>
                                <?= e(Lang::get($isActive ? 'admin.product.deactivate' : 'admin.product.activate')) ?>
                            </button>
                        </section>

                        <section class="panel-block panel-block--danger" aria-labelledby="delete-title">
                            <h3 class="panel-block__title" id="delete-title"><?= e(Lang::get('admin.product.delete_title')) ?></h3>
                            <?php if ($orderLines > 0) { ?>
                                <p class="form-field__help"><?= e(Format::plural('admin.product.delete_blocked', $orderLines)) ?></p>
                                <button type="button" class="button button--danger" id="product-delete-open" disabled><?= e(Lang::get('admin.product.delete')) ?></button>
                            <?php } else { ?>
                                <p class="form-field__help"><?= e(Lang::get('admin.product.delete_text')) ?></p>
                                <button type="submit" class="button button--danger" id="product-delete-open" form="product-delete" data-dialog-open="product-delete-dialog"><?= e(Lang::get('admin.product.delete')) ?></button>
                            <?php } ?>
                        </section>
                    <?php }],
                ],
            ]); ?>
        </form>

        <?php admin_partial('form/save-bar', [
            'form' => 'product-form',
            'save' => Lang::get($stored === null ? 'admin.product.create' : 'admin.form.save'),
        ]); ?>

        <?php /* The preview colour is not a product field: its radios belong to
                 a form that is never submitted, so they never reach the save. */ ?>
        <form id="product-preview-only" hidden></form>

        <?php if ($stored !== null) { ?>
            <form id="product-availability" method="post" action="/admin/product-save.php" hidden>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
            </form>
            <form id="product-delete" method="post" action="/admin/product-save.php" hidden>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $id ?>">
            </form>
            <?php admin_partial('form/dialog', [
                'id' => 'product-deactivate-dialog', 'form' => 'product-availability', 'danger' => false,
                'title' => Lang::get('admin.product.deactivate_q', ['name' => (string) $stored['name']]),
                'text' => Lang::get('admin.product.deactivate_text'),
                'confirm' => Lang::get('admin.product.deactivate'),
            ]); ?>
            <?php admin_partial('form/dialog', [
                'id' => 'product-delete-dialog', 'form' => 'product-delete',
                'title' => Lang::get('admin.product.delete_q', ['name' => (string) $stored['name']]),
                'text' => Lang::get('admin.product.delete_dialog_text', ['photos' => Format::plural('admin.product.photo_count', count($images))]),
                'confirm' => Lang::get('admin.product.delete'),
            ]); ?>
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
    <script type="module" src="/assets/js/admin-product-artwork.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
