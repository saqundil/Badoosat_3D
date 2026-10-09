<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Format;
use App\Support\Lang;
use App\Support\Response;
use App\Support\UploadLimit;

/*
 * The pattern gallery: every shared component of the dashboard and its
 * states, in the dashboard's current language. Not linked from the menu.
 *
 * It is a working page, not a picture of one. The forms post back here and
 * are validated by the server; nothing they send is stored anywhere. The
 * table, the stock grid and the swatches read the real catalogue, read-only,
 * so the components are judged against real content.
 */

Auth::require();
Csrf::session();

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'form') {
        $old = $_POST;
        if (trim((string) ($_POST['product_name'] ?? '')) === '') {
            $errors['product_name'] = Lang::get('admin.patterns.err_name');
        }
        $email = trim((string) ($_POST['contact_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['contact_email'] = Lang::get('admin.patterns.err_email');
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', (string) ($_POST['price'] ?? '')) !== 1) {
            $errors['price'] = Lang::get('admin.patterns.err_price');
        }
    }

    if ($errors === []) {
        $_SESSION['patterns_toast'] = Lang::get($action === 'delete' ? 'admin.patterns.deleted_demo' : 'admin.patterns.saved_demo');
        Response::redirect('/admin/_patterns.php');
    }
}

// The server-error state without having to provoke it.
if (isset($_GET['errors']) && $errors === []) {
    $errors = [
        'product_name'  => Lang::get('admin.patterns.err_name'),
        'contact_email' => Lang::get('admin.patterns.err_email'),
    ];
}

$toast = null;
if (isset($_SESSION['patterns_toast'])) {
    $toast = ['text' => (string) $_SESSION['patterns_toast']];
    unset($_SESSION['patterns_toast']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== []) {
    $toast = ['text' => Lang::get('admin.patterns.not_saved'), 'kind' => 'error'];
}

/* ------------------------------------------------- the real catalogue */

$pdo      = Db::pdo();
$colors   = $pdo->query('SELECT id, name, hex FROM colors ORDER BY sort_order, id')->fetchAll();
$sizes    = $pdo->query('SELECT id, code, label FROM sizes ORDER BY sort_order, id')->fetchAll();
$garments = $pdo->query('SELECT id, name_ar, name_en FROM garments ORDER BY sort_order, id')->fetchAll();
$product  = $pdo->query('SELECT id, name, image_path, base_price_cents FROM products ORDER BY id LIMIT 1')->fetch() ?: null;
$slide    = $pdo->query('SELECT wordmark, tagline, cta_label FROM hero_slides ORDER BY sort_order, id LIMIT 1')->fetch() ?: [];
$variants = $pdo->query(
    'SELECT v.id, v.sku, v.price_cents, v.stock, v.is_active, v.color_id, v.size_id,
            p.id AS product_id, p.name AS product, p.image_path, c.name AS colour, c.hex, s.code AS size
       FROM product_variants v
       JOIN products p ON p.id = v.product_id
       JOIN colors c   ON c.id = v.color_id
       JOIN sizes s    ON s.id = v.size_id
   ORDER BY p.id, c.sort_order, s.sort_order'
)->fetchAll();

$public = static fn (?string $path): ?string => $path !== null && $path !== ''
    && is_file(dirname(__DIR__) . '/' . ltrim($path, '/')) ? $path : null;

// The first product's grid: its colours down the side, its sizes across.
$matrix = [];
$inStock = [];
foreach ($variants as $v) {
    if ($product !== null && (int) $v['product_id'] === (int) $product['id']) {
        $matrix[(int) $v['color_id']][(int) $v['size_id']] = (int) $v['stock'];
        $inStock[(int) $v['color_id']] = ($inStock[(int) $v['color_id']] ?? 0) + (int) $v['stock'];
    }
}
$matrixColors = array_values(array_filter($colors, static fn (array $c): bool => isset($matrix[(int) $c['id']])));
$matrixSizes  = array_values(array_filter($sizes, static function (array $s) use ($matrix): bool {
    foreach ($matrix as $row) {
        if (array_key_exists((int) $s['id'], $row)) {
            return true;
        }
    }
    return false;
}));
foreach ($matrixColors as $c) {
    foreach ($matrixSizes as $s) {
        $matrix[(int) $c['id']][(int) $s['id']] ??= null;
    }
}

$colorOptions = array_map(static fn (array $c): array => [
    'value'    => (int) $c['id'],
    'label'    => (string) $c['name'],
    'hex'      => (string) $c['hex'],
    'disabled' => isset($inStock[(int) $c['id']]) && $inStock[(int) $c['id']] === 0,
    'reason'   => Lang::get('admin.patterns.sold_out'),
], $colors);

$value = static fn (string $name, string $default = '') => (string) ($old[$name] ?? $default);

$title   = Lang::get('admin.patterns.title');
$current = 'patterns';

$body = static function () use (
    $errors, $old, $value, $colors, $garments, $product, $slide, $variants, $public,
    $matrix, $matrixColors, $matrixSizes, $colorOptions
): void {
    $locale = Lang::locale();
    $toc = ['p-buttons', 'p-forms', 'p-table', 'p-studio', 'p-settings', 'p-states'];
    ?>
    <main class="dash patterns">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.patterns.title'),
            'subtitle' => Lang::get('admin.patterns.subtitle'),
        ]); ?>

        <nav class="patterns__toc" aria-label="<?= e(Lang::get('admin.patterns.toc')) ?>">
            <?php foreach ($toc as $id) { ?>
                <a class="patterns__toc-link" href="#<?= e($id) ?>"><?= e(Lang::get('admin.patterns.' . substr($id, 2))) ?></a>
            <?php } ?>
        </nav>

        <!-- ------------------------------------------------ buttons, chips -->
        <section class="patterns__section" id="p-buttons" aria-labelledby="p-buttons-title">
            <h2 class="patterns__title" id="p-buttons-title"><?= e(Lang::get('admin.patterns.buttons')) ?></h2>
            <p class="patterns__help"><?= e(Lang::get('admin.patterns.buttons_help')) ?></p>

            <div class="patterns__row">
                <button type="button" class="button button--primary"><?= e(Lang::get('admin.form.save')) ?></button>
                <button type="button" class="button button--secondary"><?= e(Lang::get('admin.patterns.preview')) ?></button>
                <button type="button" class="button button--ghost"><?= e(Lang::get('admin.form.cancel')) ?></button>
                <button type="button" class="button button--danger"><?= e(Lang::get('admin.patterns.delete')) ?></button>
                <button type="button" class="button button--danger-solid"><?= e(Lang::get('admin.patterns.delete')) ?></button>
                <button type="button" class="button button--primary" disabled><?= e(Lang::get('admin.form.save')) ?></button>
            </div>
            <div class="patterns__row">
                <button type="button" class="icon-button" aria-label="<?= e(Lang::get('admin.action.back')) ?>"><?= admin_icon('back') ?></button>
                <button type="button" class="icon-button icon-button--quiet" aria-label="<?= e(Lang::get('admin.table.actions')) ?>"><?= admin_icon('more') ?></button>
                <button type="button" class="icon-button" disabled aria-label="<?= e(Lang::get('admin.studio.undo')) ?>"><?= admin_icon('undo') ?></button>
                <span class="patterns__sep" aria-hidden="true"></span>
                <?php admin_partial('status-chip', ['label' => Lang::get('admin.patterns.chip_live'), 'tone' => 'active']); ?>
                <?php admin_partial('status-chip', ['label' => Lang::get('admin.patterns.chip_done'), 'tone' => 'done']); ?>
                <?php admin_partial('status-chip', ['label' => Lang::get('admin.patterns.chip_stopped'), 'tone' => 'stopped']); ?>
            </div>

            <div class="stat-grid patterns__stats">
                <?php admin_partial('stat-card', [
                    'label'  => Lang::get('admin.patterns.stat_variants'),
                    'icon'   => 'shirt',
                    'figure' => Format::number(count($variants)),
                    'note'   => Lang::get('admin.patterns.stat_variants_note'),
                ]); ?>
                <?php admin_partial('stat-card', [
                    'label'  => Lang::get('admin.patterns.stat_units'),
                    'icon'   => 'stack',
                    'figure' => Format::number(array_sum(array_map(static fn (array $v): int => (int) $v['stock'], $variants))),
                    'note'   => Lang::get('admin.patterns.stat_units_note'),
                ]); ?>
                <?php admin_partial('stat-card', [
                    'label'  => Lang::get('admin.patterns.stat_error'),
                    'icon'   => 'alert',
                    'figure' => null,
                ]); ?>
            </div>
        </section>

        <!-- ------------------------------------------------------- forms -->
        <section class="patterns__section" id="p-forms" aria-labelledby="p-forms-title">
            <h2 class="patterns__title" id="p-forms-title"><?= e(Lang::get('admin.patterns.forms')) ?></h2>
            <p class="patterns__help"><?= e(Lang::get('admin.patterns.forms_help')) ?>
                <a href="/admin/_patterns.php?errors=1#p-forms"><?= e(Lang::get('admin.patterns.show_errors')) ?></a></p>

            <form class="patterns__card form-stack" id="patterns-form" method="post" action="/admin/_patterns.php#p-forms" data-watch>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="form">

                <?php admin_partial('form/error-summary', ['errors' => $errors]); ?>

                <div class="form-grid">
                    <?php admin_partial('form/field', [
                        'type' => 'text', 'name' => 'product_name', 'label' => Lang::get('admin.patterns.f_name'),
                        'value' => $value('product_name', (string) ($product['name'] ?? '')), 'required' => true, 'maxlength' => 80,
                        'dir' => 'auto', 'help' => Lang::get('admin.patterns.f_name_help'), 'invalid' => Lang::get('admin.form.required'),
                        'error' => $errors['product_name'] ?? null,
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'email', 'name' => 'contact_email', 'label' => Lang::get('admin.patterns.f_email'),
                        'value' => $value('contact_email'), 'dir' => 'ltr', 'help' => Lang::get('admin.patterns.f_email_help'),
                        'invalid' => Lang::get('admin.patterns.err_email'), 'error' => $errors['contact_email'] ?? null,
                        'attrs' => ['autocomplete' => 'email'],
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'money', 'name' => 'price', 'label' => Lang::get('admin.patterns.f_price'),
                        'value' => $value('price', $product !== null ? number_format((int) $product['base_price_cents'] / 100, 2, '.', '') : '19.00'),
                        'required' => true, 'invalid' => Lang::get('admin.patterns.err_price'), 'error' => $errors['price'] ?? null,
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'number', 'name' => 'print_width', 'label' => Lang::get('admin.patterns.f_width'), 'unit' => Lang::get('admin.patterns.unit_cm'),
                        'value' => $value('print_width', '28'), 'attrs' => ['min' => 1, 'max' => 200, 'step' => '0.1'],
                        'invalid' => Lang::get('admin.patterns.err_width'),
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'select', 'name' => 'garment', 'label' => Lang::get('admin.patterns.f_garment'),
                        'value' => $value('garment', (string) ($garments[0]['id'] ?? '')),
                        'options' => array_map(static fn (array $g): array => ['value' => (int) $g['id'], 'label' => (string) ($locale === 'en' ? $g['name_en'] : $g['name_ar'])], $garments),
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'date', 'name' => 'launch', 'label' => Lang::get('admin.patterns.f_launch'), 'value' => $value('launch'),
                        'help' => Lang::get('admin.patterns.f_launch_help'),
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'segmented', 'name' => 'side', 'label' => Lang::get('admin.patterns.f_side'), 'value' => $value('side', 'front'),
                        'options' => [['value' => 'front', 'label' => Lang::get('admin.side.front')], ['value' => 'back', 'label' => Lang::get('admin.side.back')]],
                    ]); ?>
                    <?php admin_partial('form/field', [
                        'type' => 'toggle', 'name' => 'customisable', 'label' => Lang::get('admin.patterns.f_custom'),
                        'value' => isset($old['action']) ? !empty($old['customisable']) : true,
                    ]); ?>
                    <div class="form-field--wide">
                        <?php admin_partial('form/field', [
                            'type' => 'textarea', 'name' => 'description', 'label' => Lang::get('admin.patterns.f_desc'), 'maxlength' => 400,
                            'dir' => 'auto', 'value' => $value('description'), 'help' => Lang::get('admin.patterns.f_desc_help'),
                        ]); ?>
                    </div>
                    <div class="form-field--wide">
                        <?php admin_partial('form/field', [
                            'type' => 'checklist', 'name' => 'colours', 'label' => Lang::get('admin.patterns.f_colours'),
                            'value' => $old['colours'] ?? array_slice(array_column($colors, 'id'), 0, 3),
                            'options' => array_map(static fn (array $c): array => ['value' => (int) $c['id'], 'label' => (string) $c['name']], $colors),
                            'help' => Lang::get('admin.patterns.f_colours_help'),
                        ]); ?>
                    </div>
                    <div class="form-field--wide">
                        <?php admin_partial('form/field', [
                            'type' => 'radios', 'name' => 'fit', 'label' => Lang::get('admin.patterns.f_fit'), 'value' => $value('fit', 'regular'),
                            'options' => [
                                ['value' => 'regular', 'label' => Lang::get('admin.patterns.fit_regular'), 'hint' => Lang::get('admin.patterns.fit_regular_hint')],
                                ['value' => 'oversize', 'label' => Lang::get('admin.patterns.fit_oversize'), 'hint' => Lang::get('admin.patterns.fit_oversize_hint')],
                            ],
                        ]); ?>
                    </div>
                    <div class="form-field--wide">
                        <?php admin_partial('form/field', [
                            'type' => 'swatches', 'name' => 'preview_colour', 'label' => Lang::get('admin.patterns.f_swatch'),
                            'value' => $value('preview_colour', (string) ($colors[0]['id'] ?? '')), 'options' => $colorOptions,
                            'help' => Lang::get('admin.patterns.f_swatch_help'),
                        ]); ?>
                    </div>
                </div>

                <?php admin_partial('form/section', [
                    'id' => 'p-forms-more', 'title' => Lang::get('admin.patterns.f_more'), 'help' => Lang::get('admin.patterns.f_more_help'),
                    'open' => true,
                    'body' => static function () use ($matrix, $matrixColors, $matrixSizes, $product, $public): void { ?>
                        <div class="form-stack">
                            <?php if ($matrixColors !== []) {
                                admin_partial('form/stock-matrix', [
                                    'name' => 'stock', 'caption' => Lang::get('admin.patterns.f_stock', ['name' => (string) ($product['name'] ?? '')]),
                                    'colors' => array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'hex' => (string) $c['hex']], $matrixColors),
                                    'sizes' => array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'code' => (string) $s['code']], $matrixSizes),
                                    'values' => $matrix,
                                ]);
                            } ?>
                            <div class="form-grid">
                                <?php admin_partial('form/dropzone', [
                                    'name' => 'cover', 'label' => Lang::get('admin.patterns.f_cover'), 'current' => $public($product['image_path'] ?? null),
                                    'minWidth' => 800, 'minHeight' => 1000, 'ratio' => '4 / 5', 'removeName' => 'clear_cover',
                                ]); ?>
                                <?php admin_partial('form/dropzone', [
                                    'name' => 'banner', 'label' => Lang::get('admin.patterns.f_banner'), 'minWidth' => 1600, 'minHeight' => 600,
                                    'ratio' => '16 / 6', 'help' => Lang::get('admin.patterns.f_banner_help'),
                                ]); ?>
                            </div>
                        </div>
                    <?php },
                ]); ?>
            </form>
            <?php admin_partial('form/save-bar', ['form' => 'patterns-form']); ?>

            <div class="patterns__row">
                <form id="patterns-delete" method="post" action="/admin/_patterns.php" class="inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="button button--danger" data-dialog-open="patterns-dialog"><?= e(Lang::get('admin.patterns.delete_sample')) ?></button>
                </form>
                <button type="button" class="button button--secondary" data-demo-toast="<?= e(Lang::get('admin.form.saved')) ?>"><?= e(Lang::get('admin.patterns.toast_ok')) ?></button>
                <button type="button" class="button button--secondary" data-demo-toast="<?= e(Lang::get('admin.patterns.toast_error_text')) ?>" data-kind="error"><?= e(Lang::get('admin.patterns.toast_error')) ?></button>
            </div>
            <?php admin_partial('form/dialog', [
                'id' => 'patterns-dialog', 'form' => 'patterns-delete',
                'title' => Lang::get('admin.patterns.dialog_title'), 'text' => Lang::get('admin.patterns.dialog_text'),
                'confirm' => Lang::get('admin.patterns.delete_sample'),
            ]); ?>
        </section>

        <!-- ------------------------------------------------------- table -->
        <section class="patterns__section" id="p-table" aria-labelledby="p-table-title">
            <h2 class="patterns__title" id="p-table-title"><?= e(Lang::get('admin.patterns.table')) ?></h2>
            <p class="patterns__help"><?= e(Lang::get('admin.patterns.table_help')) ?></p>

            <?php admin_partial('data-table', [
                'id' => 'patterns-variants',
                'caption' => Lang::get('admin.patterns.table_caption'),
                'columns' => [
                    ['key' => 'product', 'label' => Lang::get('admin.patterns.col_product'), 'primary' => true, 'sortable' => true],
                    ['key' => 'sku', 'label' => Lang::get('admin.patterns.col_sku'), 'sortable' => true],
                    ['key' => 'colour', 'label' => Lang::get('admin.patterns.col_colour'), 'sortable' => true],
                    ['key' => 'size', 'label' => Lang::get('admin.patterns.col_size')],
                    ['key' => 'price', 'label' => Lang::get('admin.patterns.col_price'), 'align' => 'end', 'sortable' => true],
                    ['key' => 'stock', 'label' => Lang::get('admin.patterns.col_stock'), 'align' => 'end', 'sortable' => true],
                    ['key' => 'status', 'label' => Lang::get('admin.patterns.col_status')],
                ],
                'rows' => $variants,
                'rowLabel' => static fn (array $v): string => $v['product'] . ' ' . $v['sku'],
                'sortValue' => static fn (array $v, string $key): string => match ($key) {
                    'price' => (string) $v['price_cents'],
                    'stock' => (string) $v['stock'],
                    default => '',
                } ?: (string) ($v[$key] ?? ''),
                'cell' => static function (array $v, string $key) use ($public): void {
                    switch ($key) {
                        case 'product':
                            $thumb = $public($v['image_path']); ?>
                            <span class="cell-media">
                                <?php if ($thumb !== null) { ?>
                                    <img class="cell-media__thumb" src="<?= e($thumb) ?>" alt="" width="40" height="40" loading="lazy">
                                <?php } else { ?>
                                    <span class="cell-media__thumb" aria-hidden="true"></span>
                                <?php } ?>
                                <span class="cell-media__text"><span class="cell-media__title"><bdi><?= e($v['product']) ?></bdi></span></span>
                            </span>
                            <?php break;
                        case 'sku': ?><bdi class="code-text" translate="no"><?= e($v['sku']) ?></bdi><?php break;
                        case 'colour': ?><span class="swatch" style="--swatch: <?= e($v['hex']) ?>" aria-hidden="true"></span> <bdi><?= e($v['colour']) ?></bdi><?php break;
                        case 'size': ?><bdi><?= e($v['size']) ?></bdi><?php break;
                        case 'price': admin_partial('money', ['cents' => (int) $v['price_cents']]); break;
                        case 'stock': echo e(Format::number((int) $v['stock'])); break;
                        default:
                            $state = !(int) $v['is_active'] ? 'hidden' : ((int) $v['stock'] === 0 ? 'out' : 'live');
                            admin_partial('status-chip', [
                                'label' => Lang::get('admin.patterns.status_' . $state),
                                'tone'  => ['live' => 'active', 'out' => 'stopped', 'hidden' => 'done'][$state],
                            ]);
                    }
                },
                'actions' => static function (array $v): void { ?>
                    <a class="menu-item" href="/admin/product-edit.php?id=<?= (int) $v['product_id'] ?>"><?= e(Lang::get('admin.patterns.open_product')) ?></a>
                    <a class="menu-item" href="/admin/catalog.php"><?= e(Lang::get('admin.patterns.open_stock')) ?></a>
                <?php },
                'bulk' => static function (): void { ?>
                    <button type="button" class="button button--secondary" data-demo-toast="<?= e(Lang::get('admin.patterns.bulk_demo')) ?>"><?= e(Lang::get('admin.patterns.bulk_export')) ?></button>
                <?php },
            ]); ?>

            <?php admin_partial('data-table', [
                'id' => 'patterns-empty', 'caption' => Lang::get('admin.patterns.table_empty_caption'),
                'columns' => [['key' => 'name', 'label' => Lang::get('admin.patterns.col_product')]],
                'rows' => [], 'cell' => static function (): void {},
                'empty' => ['text' => Lang::get('admin.patterns.empty_text'), 'hint' => Lang::get('admin.patterns.empty_hint'),
                    'action' => ['href' => '/admin/products.php', 'text' => Lang::get('admin.patterns.empty_action')]],
            ]); ?>
        </section>

        <!-- ------------------------------------------------------ studio -->
        <section class="patterns__section" id="p-studio" aria-labelledby="p-studio-title">
            <h2 class="patterns__title" id="p-studio-title"><?= e(Lang::get('admin.patterns.studio')) ?></h2>
            <p class="patterns__help"><?= e(Lang::get('admin.patterns.studio_help')) ?></p>

            <form id="patterns-studio-form" method="post" action="/admin/_patterns.php" data-watch>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="studio">
                <?php admin_partial('studio', [
                    'id' => 'patterns-studio',
                    'label' => Lang::get('admin.patterns.studio'),
                    'saveForm' => 'patterns-studio-form',
                    'history' => true,
                    'zoom' => true,
                    'devices' => [
                        'desktop' => ['label' => Lang::get('admin.patterns.device_desktop'), 'width' => 1200],
                        'tablet'  => ['label' => Lang::get('admin.patterns.device_tablet'), 'width' => 768],
                        'phone'   => ['label' => Lang::get('admin.patterns.device_phone'), 'width' => 390],
                    ],
                    'languages' => array_combine(Lang::adminLocales(), array_map([Lang::class, 'nativeName'], Lang::adminLocales())),
                    'layers' => static function (): void {
                        foreach (['wordmark' => 'file', 'tagline' => 'file', 'cta' => 'forward'] as $key => $icon) { ?>
                            <button type="button" class="layer-row" role="option" aria-selected="false" data-studio-item="<?= e($key) ?>">
                                <span class="layer-row__icon"><?= admin_icon($icon) ?></span>
                                <?= e(Lang::get('admin.patterns.layer_' . $key)) ?>
                            </button>
                        <?php }
                    },
                    'canvas' => static function () use ($slide): void { ?>
                        <div class="patterns-slide" data-patterns-slide lang="<?= e(Lang::locale()) ?>" dir="<?= Lang::isRtl() ? 'rtl' : 'ltr' ?>">
                            <p class="patterns-slide__wordmark" data-studio-item="wordmark" data-bind="wordmark"><bdi><?= e((string) ($slide['wordmark'] ?? 'BADOOSAT')) ?></bdi></p>
                            <p class="patterns-slide__tagline" data-studio-item="tagline" data-bind="tagline"><?= e((string) ($slide['tagline'] ?? '')) ?></p>
                            <span class="patterns-slide__cta" data-studio-item="cta" data-bind="cta"><?= e((string) ($slide['cta_label'] ?? '')) ?></span>
                        </div>
                    <?php },
                    'panels' => [
                        'content' => ['label' => Lang::get('admin.patterns.tab_content'), 'body' => static function () use ($slide): void {
                            admin_partial('form/field', ['type' => 'text', 'name' => 'wordmark', 'id' => 'ps-wordmark', 'label' => Lang::get('admin.patterns.layer_wordmark'),
                                'value' => (string) ($slide['wordmark'] ?? ''), 'dir' => 'auto', 'maxlength' => 24, 'attrs' => ['data-bind-to' => 'wordmark']]);
                            admin_partial('form/field', ['type' => 'textarea', 'name' => 'tagline', 'id' => 'ps-tagline', 'label' => Lang::get('admin.patterns.layer_tagline'),
                                'value' => (string) ($slide['tagline'] ?? ''), 'dir' => 'auto', 'maxlength' => 200, 'attrs' => ['data-bind-to' => 'tagline']]);
                            admin_partial('form/field', ['type' => 'text', 'name' => 'cta', 'id' => 'ps-cta', 'label' => Lang::get('admin.patterns.layer_cta'),
                                'value' => (string) ($slide['cta_label'] ?? ''), 'dir' => 'auto', 'maxlength' => 40, 'attrs' => ['data-bind-to' => 'cta']]);
                        }],
                        'style' => ['label' => Lang::get('admin.patterns.tab_style'), 'body' => static function (): void {
                            admin_partial('form/field', ['type' => 'segmented', 'name' => 'align', 'id' => 'ps-align', 'label' => Lang::get('admin.patterns.f_align'), 'value' => 'start',
                                'options' => [['value' => 'start', 'label' => Lang::get('admin.patterns.align_start')], ['value' => 'center', 'label' => Lang::get('admin.patterns.align_center')]]]);
                            admin_partial('form/field', ['type' => 'toggle', 'name' => 'badges', 'id' => 'ps-badges', 'label' => Lang::get('admin.patterns.f_badges'), 'value' => true]);
                        }],
                        'settings' => ['label' => Lang::get('admin.patterns.tab_settings'), 'body' => static function (): void {
                            admin_partial('form/field', ['type' => 'toggle', 'name' => 'active', 'id' => 'ps-active', 'label' => Lang::get('admin.patterns.f_active'), 'value' => true]);
                            admin_partial('form/field', ['type' => 'number', 'name' => 'order', 'id' => 'ps-order', 'label' => Lang::get('admin.patterns.f_order'), 'value' => '1', 'attrs' => ['min' => 1]]);
                        }],
                    ],
                ]); ?>
            </form>
            <?php admin_partial('form/save-bar', ['form' => 'patterns-studio-form']); ?>
        </section>

        <!-- ---------------------------------------------------- settings -->
        <section class="patterns__section" id="p-settings" aria-labelledby="p-settings-title">
            <h2 class="patterns__title" id="p-settings-title"><?= e(Lang::get('admin.patterns.settings')) ?></h2>
            <p class="patterns__help"><?= e(Lang::get('admin.patterns.settings_help')) ?></p>

            <form id="patterns-settings" method="post" action="/admin/_patterns.php" data-watch>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="settings">
                <?php admin_partial('settings', [
                    'label' => Lang::get('admin.patterns.settings'),
                    'sections' => [
                        's-shop' => ['title' => Lang::get('admin.patterns.s_shop'), 'help' => Lang::get('admin.patterns.s_shop_help'), 'body' => static function (): void { ?>
                            <div class="form-grid">
                                <?php admin_partial('form/field', ['type' => 'text', 'name' => 'shop_name', 'label' => Lang::get('admin.patterns.s_shop_name'), 'value' => 'BADOOSAT', 'dir' => 'auto', 'required' => true, 'invalid' => Lang::get('admin.form.required')]); ?>
                                <?php admin_partial('form/field', ['type' => 'tel', 'name' => 'shop_phone', 'label' => Lang::get('admin.patterns.s_shop_phone'), 'dir' => 'ltr', 'attrs' => ['autocomplete' => 'tel', 'inputmode' => 'tel']]); ?>
                            </div>
                        <?php }],
                        's-print' => ['title' => Lang::get('admin.patterns.s_print'), 'help' => Lang::get('admin.patterns.s_print_help'), 'body' => static function (): void { ?>
                            <div class="form-grid">
                                <?php admin_partial('form/field', ['type' => 'number', 'name' => 'dpi', 'label' => Lang::get('admin.patterns.s_dpi'), 'unit' => 'DPI', 'value' => '300', 'attrs' => ['min' => 72, 'max' => 600]]); ?>
                                <?php admin_partial('form/field', ['type' => 'segmented', 'name' => 'colour_space', 'label' => Lang::get('admin.patterns.s_colour'), 'value' => 'cmyk',
                                    'options' => [['value' => 'cmyk', 'label' => 'CMYK'], ['value' => 'rgb', 'label' => 'RGB']]]); ?>
                            </div>
                        <?php }],
                        's-uploads' => ['title' => Lang::get('admin.patterns.s_uploads'), 'help' => Lang::get('admin.patterns.s_uploads_help'),
                            'badge' => ['text' => Lang::get('admin.patterns.read_only'), 'kind' => 'done'],
                            'body' => static function (): void { ?>
                                <dl class="facts-list">
                                    <?php foreach (UploadLimit::sources() as $source => $bytes) { ?>
                                        <div><dt><bdi><?= e((string) $source) ?></bdi></dt><dd><?= e(Lang::get('admin.patterns.megabytes', ['n' => UploadLimit::mebibytes((int) $bytes)])) ?></dd></div>
                                    <?php } ?>
                                </dl>
                            <?php }],
                    ],
                ]); ?>
            </form>
            <?php admin_partial('form/save-bar', ['form' => 'patterns-settings']); ?>
        </section>

        <!-- ------------------------------------------------------ states -->
        <section class="patterns__section" id="p-states" aria-labelledby="p-states-title">
            <h2 class="patterns__title" id="p-states-title"><?= e(Lang::get('admin.patterns.states')) ?></h2>
            <p class="patterns__help"><?= e(Lang::get('admin.patterns.states_help')) ?></p>
            <div class="patterns__states">
                <div class="patterns__card"><?php admin_partial('state', ['kind' => 'empty', 'text' => Lang::get('admin.patterns.empty_text'), 'hint' => Lang::get('admin.patterns.empty_hint'),
                    'action' => ['href' => '/admin/products.php', 'text' => Lang::get('admin.patterns.empty_action')]]); ?></div>
                <div class="patterns__card"><?php admin_partial('state', ['kind' => 'loading', 'text' => Lang::get('admin.patterns.loading_text')]); ?></div>
                <div class="patterns__card"><?php admin_partial('state', ['kind' => 'error', 'text' => Lang::get('admin.state.error_title'), 'hint' => Lang::get('admin.state.error_hint')]); ?></div>
                <div class="patterns__card patterns__card--dark"><?php admin_partial('state', ['kind' => 'empty', 'onDark' => true, 'compact' => true, 'text' => Lang::get('admin.patterns.empty_dark')]); ?></div>
            </div>
        </section>
    </main>

    <script type="module">
        // Gallery-only wiring: toast buttons and the studio's mock slide.
        document.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-demo-toast]') : null;
            if (button && window.adminToast) {
                window.adminToast(button.dataset.demoToast, button.dataset.kind || 'ok');
            }
        });

        const studio = document.getElementById('patterns-studio');
        const slide = studio?.querySelector('[data-patterns-slide]');
        if (studio && slide) {
            const fields = [...studio.querySelectorAll('[data-bind-to]')];
            const paint = (field) => {
                const target = slide.querySelector('[data-bind="' + field.dataset.bindTo + '"]');
                if (target) {
                    target.textContent = field.value;
                }
            };
            // A small real history: every committed change can be undone and redone.
            const past = [];
            const future = [];
            const state = () => fields.map((f) => f.value);
            let last = state();
            const sync = () => studio.setHistory?.(past.length > 0, future.length > 0);
            const apply = (values) => {
                fields.forEach((f, i) => { f.value = values[i]; paint(f); f.dispatchEvent(new Event('input', { bubbles: true })); });
                last = values;
                sync();
            };
            fields.forEach((field) => {
                field.addEventListener('input', () => paint(field));
                field.addEventListener('change', () => { past.push(last); future.length = 0; last = state(); sync(); });
            });
            studio.addEventListener('studio:undo', () => { if (past.length) { future.push(state()); apply(past.pop()); } });
            studio.addEventListener('studio:redo', () => { if (future.length) { past.push(state()); apply(future.pop()); } });
            studio.addEventListener('studio:lang', (event) => {
                slide.lang = event.detail.lang;
                slide.dir = event.detail.lang === 'ar' ? 'rtl' : 'ltr';
            });
            studio.addEventListener('studio:select', (event) => {
                document.getElementById('ps-' + event.detail.key)?.focus({ preventScroll: true });
            });
            for (const radio of studio.querySelectorAll('input[name="align"]')) {
                radio.addEventListener('change', () => { slide.dataset.align = radio.value; });
            }
            sync();
        }
    </script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
