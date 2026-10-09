<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\GarmentService;
use App\Service\ProductManagementService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\GarmentModels;
use App\Support\Lang;
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
 * Two views on one route: the list (template C), and one type's form
 * (?id=N, or ?new=1 for a new one). The form posts to garment-save.php as it
 * always has.
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

$showForm = $editing !== null || isset($_GET['new']);
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

$locale = Lang::locale();
$name = static fn (array $type): string => (string) ($locale === 'en' && trim((string) $type['name_en']) !== '' ? $type['name_en'] : $type['name_ar']);
$cm = static fn (mixed $value): string => Format::number((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 1);

$palette = $products->colors();
$sizeChart = $products->sizes();

$title = $showForm
    ? ($editing === null ? Lang::get('admin.garments.new_title') : Lang::get('admin.garments.edit_title', ['name' => $name($garment)]))
    : Lang::get('admin.garments.title');
$current = 'garments';
$toast = $notice !== null ? ['text' => $notice] : null;

if (!$showForm) {
    /* ============================================================ the list */

    $types = $service->all();
    $swatches = array_column($palette, null, 'id');
    $sizeCodes = array_column($sizeChart, 'code', 'id');

    $body = static function () use ($types, $swatches, $sizeCodes, $name, $cm): void {
        $blocked = array_values(array_filter($types, static fn (array $t): bool => $t['editor_problem'] !== null));
        $shared = array_values(array_filter($types, static fn (array $t): bool => (bool) $t['model_shared']));
        ?>
        <main class="dash">
            <?php admin_partial('page-header', [
                'title'    => Lang::get('admin.garments.title'),
                'subtitle' => Lang::get('admin.garments.subtitle'),
                'actions'  => static function (): void { ?>
                    <a class="button button--primary" href="/admin/garments.php?new=1">
                        <?= admin_icon('plus') ?><?= e(Lang::get('admin.garments.new')) ?>
                    </a>
                <?php },
            ]); ?>

            <?php if ($blocked !== []) { ?>
                <section class="alert alert--info" aria-labelledby="garments-blocked">
                    <h2 class="alert__title" id="garments-blocked"><?= admin_icon('alert') ?><?= e(Lang::get('admin.garments.blocked_title')) ?></h2>
                    <ul class="alert__list">
                        <?php foreach ($blocked as $type) { ?>
                            <li>
                                <a href="/admin/garments.php?id=<?= (int) $type['id'] ?>"><bdi><?= e($name($type)) ?></bdi></a>:
                                <?= e((string) $type['editor_problem']) ?>
                            </li>
                        <?php } ?>
                    </ul>
                </section>
            <?php } ?>

            <?php if ($shared !== []) { ?>
                <p class="alert alert--info">
                    <?= e(Lang::get('admin.garments.shared_note', [
                        'names' => implode(Lang::get('admin.list_sep'), array_map($name, $shared)),
                    ])) ?>
                </p>
            <?php } ?>

            <?php admin_partial('data-table', [
                'id'       => 'garment-types',
                'caption'  => Lang::get('admin.garments.title'),
                'columns'  => [
                    ['key' => 'name', 'label' => Lang::get('admin.garments.col.type'), 'primary' => true, 'sortable' => true],
                    ['key' => 'base', 'label' => Lang::get('admin.garments.col.base')],
                    ['key' => 'model', 'label' => Lang::get('admin.garments.col.model')],
                    ['key' => 'area', 'label' => Lang::get('admin.garments.col.area')],
                    ['key' => 'colours', 'label' => Lang::get('admin.garments.col.colours')],
                    ['key' => 'sizes', 'label' => Lang::get('admin.garments.col.sizes')],
                    ['key' => 'products', 'label' => Lang::get('admin.garments.col.products'), 'align' => 'end', 'sortable' => true],
                    ['key' => 'status', 'label' => Lang::get('admin.garments.col.status'), 'sortable' => true],
                ],
                'rows'      => $types,
                'rowLabel'  => $name,
                'sortValue' => static fn (array $t, string $key): string => match ($key) {
                    'name'     => $name($t),
                    'products' => (string) (int) $t['product_count'],
                    'status'   => (string) (int) $t['is_active'],
                    default    => '',
                },
                'cell' => static function (array $t, string $key) use ($swatches, $sizeCodes, $name, $cm): void {
                    switch ($key) {
                        case 'name': ?>
                            <span class="cell-media__text">
                                <a class="cell-media__title" href="/admin/garments.php?id=<?= (int) $t['id'] ?>"><bdi><?= e($name($t)) ?></bdi></a>
                                <bdi class="cell-media__sub code-text" translate="no"><?= e((string) $t['key']) ?></bdi>
                            </span>
                            <?php break;
                        case 'base':
                            if ($t['base_product_name'] === null) {
                                admin_partial('status-chip', ['label' => Lang::get('admin.garments.base_none'), 'tone' => 'stopped']);
                            } else { ?>
                                <bdi><?= e((string) $t['base_product_name']) ?></bdi>
                                <?php if ($t['base_product_printed']) { ?>
                                    <span class="cell-media__sub"><?= e(Lang::get('admin.garments.base_printed_short')) ?></span>
                                <?php } ?>
                            <?php }
                            break;
                        case 'model':
                            if ($t['model_missing']) {
                                admin_partial('status-chip', ['label' => Lang::get('admin.garments.model_missing'), 'tone' => 'stopped']);
                            } else { ?>
                                <bdi><?= e((string) $t['model_label']) ?></bdi>
                                <?php if ($t['model_shared']) { ?>
                                    <span class="cell-media__sub"><?= e(Lang::get('admin.garments.model_shared')) ?></span>
                                <?php } ?>
                            <?php }
                            break;
                        case 'area':
                            echo e(Lang::get('admin.garments.area_value', ['w' => $cm($t['print_area_w_cm']), 'h' => $cm($t['print_area_h_cm'])]));
                            break;
                        case 'colours': ?>
                            <span class="swatch-row" aria-label="<?= e(Format::plural('admin.garments.colour_count', count($t['colors']))) ?>" role="img">
                                <?php foreach (array_slice($t['colors'], 0, 8) as $colourId) {
                                    $c = $swatches[$colourId] ?? null;
                                    if ($c !== null) { ?><span class="swatch swatch--on-light" style="--swatch: <?= e((string) $c['hex']) ?>" title="<?= e((string) $c['name']) ?>"></span><?php }
                                } ?>
                                <?php if (count($t['colors']) > 8) { ?><span class="swatch-row__more">+<?= e(Format::number(count($t['colors']) - 8)) ?></span><?php } ?>
                                <?php if ($t['colors'] === []) { ?><span class="cell-media__sub"><?= e(Lang::get('admin.garments.none')) ?></span><?php } ?>
                            </span>
                            <?php break;
                        case 'sizes':
                            $codes = array_values(array_filter(array_map(static fn (int $sid): ?string => $sizeCodes[$sid] ?? null, $t['sizes'])));
                            echo $codes === [] ? '<span class="cell-media__sub">' . e(Lang::get('admin.garments.none')) . '</span>'
                                : '<bdi translate="no">' . e(implode(' · ', $codes)) . '</bdi>';
                            break;
                        case 'products':
                            echo e(Format::number((int) $t['product_count']));
                            break;
                        default:
                            admin_partial('status-chip', (int) $t['is_active']
                                ? ['label' => Lang::get('admin.garments.active'), 'tone' => 'active']
                                : ['label' => Lang::get('admin.garments.inactive'), 'tone' => 'done']);
                    }
                },
                'actions' => static function (array $t): void { ?>
                    <a class="menu-item" href="/admin/garments.php?id=<?= (int) $t['id'] ?>"><?= e(Lang::get('admin.garments.edit')) ?></a>
                    <a class="menu-item" href="/admin/products.php"><?= e(Lang::get('admin.garments.open_products')) ?></a>
                <?php },
                'empty' => [
                    'text'   => Lang::get('admin.garments.empty'),
                    'hint'   => Lang::get('admin.garments.empty_hint'),
                    'action' => ['href' => '/admin/garments.php?new=1', 'text' => Lang::get('admin.garments.new')],
                ],
            ]); ?>
        </main>
        <?php
    };

    require dirname(__DIR__, 2) . '/src/View/admin.php';
    exit;
}

/* ================================================================ the form */

$baseCandidates = $editing === null ? [] : $service->baseProductCandidates((int) $garment['id']);
$models = GarmentModels::all();
$usage = $editing === null ? [] : $service->colourUsage((int) $garment['id']);
$keyLocked = $editing !== null && (int) $editing['product_count'] > 0;

// A locked key is read-only, so it always shows the stored one -- never a
// refused change that could not then be corrected.
if ($keyLocked) {
    $garment['key'] = (string) $editing['key'];
}

$body = static function () use (
    $garment, $editing, $palette, $sizeChart, $models, $baseCandidates, $usage, $keyLocked, $errors, $name
): void {
    $err = static fn (string $field): ?string => $errors[$field] ?? null;
    ?>
    <main class="dash dash--form">
        <?php admin_partial('page-header', [
            'back'     => '/admin/garments.php',
            'title'    => $editing === null ? Lang::get('admin.garments.new_title') : Lang::get('admin.garments.edit_title', ['name' => $name($garment)]),
            'subtitle' => Lang::get($editing === null ? 'admin.garments.new_subtitle' : 'admin.garments.edit_subtitle'),
        ]); ?>

        <?php admin_partial('form/error-summary', [
            'errors' => $errors,
            'ids'    => ['colors' => 'f-colors-0', 'sizes' => 'f-sizes-0'],
        ]); ?>

        <?php if ($editing !== null && $garment['editor_problem'] !== null) { ?>
            <p class="alert alert--info"><?= admin_icon('alert') ?> <?= e((string) $garment['editor_problem']) ?></p>
        <?php } ?>

        <form class="form-stack" id="garment-form" method="post" action="/admin/garment-save.php" data-watch>
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $garment['id'] ?>">
            <input type="hidden" name="confirm_colours" value="0" data-uncheck-confirmed>

            <?php admin_partial('form/section', [
                'id' => 'g-names', 'title' => Lang::get('admin.garments.s.names'), 'help' => Lang::get('admin.garments.s.names_help'),
                'body' => static function () use ($garment, $err, $keyLocked, $editing): void { ?>
                    <div class="form-grid">
                        <?php admin_partial('form/field', [
                            'type' => 'text', 'name' => 'name_ar', 'label' => Lang::get('admin.garments.f.name_ar'),
                            'value' => (string) $garment['name_ar'], 'required' => true, 'maxlength' => 120, 'dir' => 'rtl',
                            'attrs' => ['lang' => 'ar'], 'invalid' => Lang::get('admin.form.required'), 'error' => $err('name_ar'),
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'text', 'name' => 'name_en', 'label' => Lang::get('admin.garments.f.name_en'),
                            'value' => (string) $garment['name_en'], 'required' => true, 'maxlength' => 120, 'dir' => 'ltr',
                            'attrs' => ['lang' => 'en'], 'invalid' => Lang::get('admin.form.required'), 'error' => $err('name_en'),
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'text', 'name' => 'key', 'label' => Lang::get('admin.garments.f.key'),
                            'value' => (string) $garment['key'], 'required' => true, 'maxlength' => 60, 'dir' => 'ltr',
                            'help' => $keyLocked
                                ? Format::plural('admin.garments.key_locked', (int) $editing['product_count'])
                                : Lang::get('admin.garments.key_help'),
                            'invalid' => Lang::get('admin.garments.err.key_format'), 'error' => $err('key'),
                            'attrs' => ['pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'spellcheck' => 'false', 'autocapitalize' => 'off',
                                'autocomplete' => 'off', 'translate' => 'no', 'readonly' => $keyLocked],
                        ]); ?>
                    </div>
                <?php },
            ]); ?>

            <?php admin_partial('form/section', [
                'id' => 'g-model', 'title' => Lang::get('admin.garments.s.model'), 'help' => Lang::get('admin.garments.s.model_help'),
                'body' => static function () use ($garment, $err, $models): void { ?>
                    <div class="form-grid">
                        <?php admin_partial('form/field', [
                            'type' => 'select', 'name' => 'model_key', 'label' => Lang::get('admin.garments.f.model_key'),
                            'value' => (string) $garment['model_key'], 'required' => true, 'error' => $err('model_key'),
                            'help' => Lang::get('admin.garments.model_help'),
                            'options' => array_map(static fn (string $key, array $model): array => [
                                'value' => $key,
                                'label' => "\u{2068}" . GarmentModels::labelFor($key) . "\u{2069}" . (!empty($model['shared_model']) ? ' — ' . Lang::get('admin.garments.model_shared') : ''),
                            ], array_keys($models), $models),
                        ]); ?>
                        <?php foreach (['print_area_w_cm', 'print_area_h_cm'] as $field) {
                            admin_partial('form/field', [
                                'type' => 'number', 'name' => $field, 'label' => Lang::get('admin.garments.f.' . $field),
                                'unit' => Lang::get('admin.unit.cm'), 'value' => (string) (float) $garment[$field], 'required' => true,
                                'attrs' => ['min' => 1, 'max' => 200, 'step' => '0.1', 'inputmode' => 'decimal'],
                                'invalid' => Lang::get('admin.garments.err.area'), 'error' => $err($field),
                            ]);
                        } ?>
                    </div>
                <?php },
            ]); ?>

            <?php admin_partial('form/section', [
                'id' => 'g-editor', 'title' => Lang::get('admin.garments.s.editor'), 'help' => Lang::get('admin.garments.s.editor_help'),
                'body' => static function () use ($garment, $err, $editing, $baseCandidates): void {
                    if ($editing === null) { ?>
                        <p class="form-field__help"><?= e(Lang::get('admin.garments.base_after_save')) ?></p>
                    <?php return;
                    }
                    $options = [['value' => 0, 'label' => Lang::get('admin.garments.base_option_none')]];
                    foreach ($baseCandidates as $candidate) {
                        // An <option> cannot hold <bdi>: first-strong isolates keep a
                        // name in the other script from reordering the sentence.
                        $options[] = [
                            'value' => (int) $candidate['id'],
                            'label' => Lang::get($candidate['artwork_path'] !== null ? 'admin.garments.base_option_printed' : 'admin.garments.base_option', [
                                'name' => "\u{2068}" . $candidate['name'] . "\u{2069}",
                                'variants' => Format::plural('admin.garments.variant_count', (int) $candidate['active_variants']),
                            ]),
                        ];
                    } ?>
                    <div class="form-grid">
                        <div class="form-field--wide">
                            <?php admin_partial('form/field', [
                                'type' => 'select', 'name' => 'base_product_id', 'label' => Lang::get('admin.garments.f.base_product_id'),
                                'value' => (string) (int) $garment['base_product_id'], 'options' => $options, 'error' => $err('base_product_id'),
                                'help' => Lang::get($baseCandidates === [] ? 'admin.garments.base_help_none' : 'admin.garments.base_help'),
                            ]); ?>
                        </div>
                    </div>
                    <?php if ($garment['base_product_printed']) { ?>
                        <p class="alert alert--info"><?= e(Lang::get('admin.garments.base_printed')) ?></p>
                    <?php }
                },
            ]); ?>

            <?php admin_partial('form/section', [
                'id' => 'g-colours', 'title' => Lang::get('admin.garments.s.colours'), 'help' => Lang::get('admin.garments.s.colours_help'),
                'body' => static function () use ($garment, $editing, $err, $palette, $usage): void {
                    $stored = array_map('intval', $editing['colors'] ?? []);
                    admin_partial('form/field', [
                        'type' => 'checklist', 'name' => 'colors', 'id' => 'f-colors', 'label' => Lang::get('admin.garments.f.colors'), 'hideLabel' => true,
                        'value' => $garment['colors'], 'error' => $err('colors'),
                        'options' => array_map(static function (array $c) use ($usage, $stored): array {
                            $users = $usage[(int) $c['id']] ?? [];
                            $option = ['value' => (int) $c['id'], 'label' => (string) $c['name'], 'hex' => (string) $c['hex']];
                            // Only a colour the type has now, and products use, needs asking about.
                            if ($users !== [] && in_array((int) $c['id'], $stored, true)) {
                                $option['attrs'] = [
                                    'data-uncheck-confirm' => 'colour-dialog',
                                    'data-uncheck-title'   => Lang::get('admin.garments.colour_dialog_title', ['colour' => (string) $c['name']]),
                                    'data-uncheck-text'    => Format::plural('admin.garments.colour_dialog_text', count($users)),
                                    'data-uncheck-items'   => json_encode($users, JSON_UNESCAPED_UNICODE),
                                ];
                            }
                            return $option;
                        }, $palette),
                    ]);
                },
            ]); ?>

            <?php admin_partial('form/section', [
                'id' => 'g-sizes', 'title' => Lang::get('admin.garments.s.sizes'), 'help' => Lang::get('admin.garments.s.sizes_help'),
                'body' => static function () use ($garment, $err, $sizeChart): void {
                    admin_partial('form/field', [
                        'type' => 'checklist', 'name' => 'sizes', 'id' => 'f-sizes', 'label' => Lang::get('admin.garments.f.sizes'), 'hideLabel' => true,
                        'value' => $garment['sizes'], 'error' => $err('sizes'),
                        'options' => array_map(static fn (array $s): array => [
                            'value' => (int) $s['id'],
                            'label' => Lang::get('admin.garments.size_option', [
                                'code'  => (string) $s['code'],
                                'chest' => Format::number((float) $s['chest_cm']),
                            ]),
                        ], $sizeChart),
                    ]);
                },
            ]); ?>

            <?php admin_partial('form/section', [
                'id' => 'g-visibility', 'title' => Lang::get('admin.garments.s.visibility'),
                'body' => static function () use ($garment, $err): void { ?>
                    <div class="form-grid">
                        <?php admin_partial('form/field', [
                            'type' => 'toggle', 'name' => 'is_active', 'label' => Lang::get('admin.garments.f.is_active'),
                            'value' => (bool) (int) $garment['is_active'],
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'number', 'name' => 'sort_order', 'label' => Lang::get('admin.garments.f.sort_order'),
                            'value' => (string) (int) $garment['sort_order'], 'help' => Lang::get('admin.garments.sort_help'),
                            'attrs' => ['min' => 0, 'max' => 999, 'step' => 1, 'inputmode' => 'numeric'], 'error' => $err('sort_order'),
                        ]); ?>
                    </div>
                <?php },
            ]); ?>
        </form>

        <?php admin_partial('form/save-bar', [
            'form' => 'garment-form',
            'save' => Lang::get($editing === null ? 'admin.garments.create' : 'admin.form.save'),
        ]); ?>

        <dialog class="dialog" id="colour-dialog" aria-labelledby="colour-dialog-title" aria-describedby="colour-dialog-text">
            <div class="dialog__body">
                <span class="dialog__icon dialog__icon--danger"><?= admin_icon('alert') ?></span>
                <h2 class="dialog__title" id="colour-dialog-title" data-fill="title"></h2>
                <p class="dialog__text" id="colour-dialog-text" data-fill="text"></p>
                <ul class="dialog__list" data-fill="items"></ul>
                <p class="dialog__text"><?= e(Lang::get('admin.garments.colour_dialog_after')) ?></p>
            </div>
            <div class="dialog__actions">
                <button type="button" class="button button--secondary" data-uncheck-keep autofocus><?= e(Lang::get('admin.garments.colour_keep')) ?></button>
                <button type="button" class="button button--danger-solid" data-uncheck-accept><?= e(Lang::get('admin.garments.colour_remove')) ?></button>
            </div>
        </dialog>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
