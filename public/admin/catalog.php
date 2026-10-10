<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\DesignRepository;
use App\Service\GarmentService;
use App\Service\ProductManagementService;
use App\Service\ValidationException;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\Lang;
use App\Support\Price;
use App\Support\Response;
use App\Support\Validator;

Auth::require();

/*
 * Stock: every product's stock as a colour-by-size grid, with its prices and
 * "on sale" boxes one fold below. One form, one save bar for the page.
 *
 * Only what the owner changed is written, and only if the database still
 * holds what the page showed (ProductManagementService::saveStock): orders
 * placed while the page is open keep the units they took.
 */

/** A variant at or under this many units counts as running low. */
const LOW_STOCK = 4;

$service  = ProductManagementService::make();
$designs  = DesignRepository::make();

$errors = [];
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'stock') {
        try {
            $result = $service->saveStock($_POST);
            $_SESSION['notice'] = $result['changed'] === 0
                ? Lang::get('admin.stock.nothing_changed')
                : Format::plural('admin.stock.saved', $result['changed']);
            Response::redirect('/admin/catalog.php');
        } catch (ValidationException $e) {
            // Re-drawn in this request, with what was typed: nothing was saved.
            $errors = $e->errors();
            $posted = $_POST;
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
        $_SESSION['errors'] = ['design' => Lang::get('admin.stock.designs_closed')];
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
}

$notice = $_SESSION['notice'] ?? null;

/*
 * Errors that survived a redirect, as the product editor does it: the
 * disabled design write point redirects, so its message would otherwise
 * vanish.
 */
$errors = array_merge($errors, (array) ($_SESSION['errors'] ?? []));
unset($_SESSION['notice'], $_SESSION['errors']);

/* ------------------------------------------------------- what is drawn */

$locale = Lang::locale();
$typeNames = [];
foreach (GarmentService::make()->all() as $type) {
    $typeNames[(int) $type['id']] = (string) ($locale === 'en' && trim((string) $type['name_en']) !== '' ? $type['name_en'] : $type['name_ar']);
}

$public = (string) Config::get('app.paths.public');
$all = $service->paginate('', 1, 100, 'name', 'asc')['items'];
$facts = $service->listFacts(array_map('intval', array_column($all, 'id')));

$cards = [];
$summary = ['units' => 0, 'out' => 0, 'low' => 0];

foreach ($all as $product) {
    $pid = (int) $product['id'];
    $colors = [];
    $sizes = [];
    $rows = [];

    foreach ($service->variantRows($pid) as $v) {
        $vid = (int) $v['id'];
        $moved = isset($errors['moved_' . $vid]);

        /* A refused save shows what was typed -- except where the database
           moved under the page: there it shows the figure that is true now. */
        $useTyped = $posted !== null && !$moved;
        $stock = $useTyped ? (string) ($posted['stock'][$vid] ?? $v['stock']) : (string) (int) $v['stock'];
        $price = $useTyped ? (string) ($posted['price'][$vid] ?? '') : number_format((int) $v['price_cents'] / 100, 2, '.', '');
        $active = $useTyped ? !empty($posted['active'][$vid]) : (int) $v['is_active'] === 1;
        $was = $useTyped && isset($posted['was'][$vid])
            ? (string) $posted['was'][$vid]
            : (int) $v['stock'] . '|' . (int) $v['price_cents'] . '|' . (int) $v['is_active'];

        $colors[(int) $v['color_id']] = ['id' => (int) $v['color_id'], 'name' => (string) $v['color_name'], 'hex' => (string) $v['color_hex']];
        $sizes[(int) $v['size_id']] = ['id' => (int) $v['size_id'], 'code' => (string) $v['size_code']];
        $rows[(int) $v['color_id']][(int) $v['size_id']] = [
            'id' => $vid, 'stock' => $stock, 'price' => $price, 'active' => $active, 'was' => $was,
            'error' => $errors['stock_' . $vid] ?? $errors['moved_' . $vid] ?? null,
            'price_error' => $errors['price_' . $vid] ?? null,
        ];

        if ((int) $v['is_active'] === 1) {
            $summary['units'] += (int) $v['stock'];
            $summary['out'] += (int) $v['stock'] === 0 ? 1 : 0;
            $summary['low'] += (int) $v['stock'] > 0 && (int) $v['stock'] <= LOW_STOCK ? 1 : 0;
        }
    }

    $thumb = null;
    foreach ([(string) ($product['image_path'] ?? ''), (string) ($facts[$pid]['thumb'] ?? '')] as $path) {
        if ($thumb === null && $path !== '' && !str_contains($path, '..') && is_file($public . $path)) {
            $thumb = $path;
        }
    }

    $cards[] = [
        'product' => $product,
        'type' => $typeNames[(int) $product['garment_id']] ?? null,
        'thumb' => $thumb,
        'colors' => array_values($colors),
        'sizes' => array_values($sizes),
        'rows' => $rows,
        'stock' => $facts[$pid]['stock'] ?? 0,
        'priceErrors' => array_filter(array_merge(...array_map(static fn (array $r): array => array_column($r, 'price_error'), $rows ?: [[]]))) !== [],
    ];
}

$title   = Lang::get('admin.stock.title');
$current = 'catalog';
$toast   = $notice !== null ? ['text' => $notice] : null;

$body = static function () use ($cards, $summary, $designs, $errors, $public): void {
    $ids = [];
    foreach (array_keys($errors) as $key) {
        if (preg_match('/^(stock|moved|price)_(\d+)$/', (string) $key, $m)) {
            $ids[$key] = ($m[1] === 'price' ? 'price-' : 'stock-') . $m[2];
        }
    }
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.stock.title'),
            'subtitle' => Lang::get('admin.stock.subtitle'),
        ]); ?>

        <?php admin_partial('form/error-summary', ['errors' => $errors, 'ids' => $ids + ['design' => 'stock-designs']]); ?>

        <p class="stock-summary" role="status">
            <span><?= e(Lang::get('admin.stock.units', ['n' => Format::number($summary['units'])])) ?></span>
            <span class="<?= $summary['out'] > 0 ? 'stock-summary__bad' : '' ?>"><?= e(Format::plural('admin.stock.out_count', $summary['out'])) ?></span>
            <span><?= e(Format::plural('admin.stock.low_count', $summary['low'])) ?></span>
        </p>

        <?php if ($cards === []) {
            admin_partial('state', ['kind' => 'empty', 'text' => Lang::get('admin.products.empty'),
                'action' => ['href' => '/admin/product-edit.php', 'text' => Lang::get('admin.products.new')]]);
        } ?>

        <form id="stock-form" class="stock-list" method="post" action="/admin/catalog.php" data-watch>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="stock">

            <?php foreach ($cards as $card) {
                $p = $card['product'];
                $pid = (int) $p['id'];
                $rows = $card['rows']; ?>
                <section class="stock-card" aria-labelledby="stock-p-<?= $pid ?>">
                    <header class="stock-card__head">
                        <?php if ($card['thumb'] !== null) { ?>
                            <img class="cell-media__thumb" src="<?= e($card['thumb']) ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
                        <?php } else { ?>
                            <span class="cell-media__thumb" aria-hidden="true"></span>
                        <?php } ?>
                        <div class="stock-card__titles">
                            <h2 class="stock-card__title" id="stock-p-<?= $pid ?>">
                                <a href="/admin/product-edit.php?id=<?= $pid ?>"><bdi><?= e((string) $p['name']) ?></bdi></a>
                            </h2>
                            <p class="cell-media__sub">
                                <?php if ($card['type'] !== null) { ?><bdi><?= e($card['type']) ?></bdi> · <?php } ?>
                                <?= e(Lang::get('admin.stock.units', ['n' => Format::number($card['stock'])])) ?>
                            </p>
                        </div>
                        <?php if (!(int) $p['is_active']) {
                            admin_partial('status-chip', ['label' => Lang::get('admin.products.status.hidden'), 'tone' => 'done']);
                        } ?>
                    </header>

                    <?php if ($rows === []) { ?>
                        <p class="form-field__help"><?= e(Lang::get('admin.stock.no_variants')) ?>
                            <a href="/admin/product-edit.php?id=<?= $pid ?>"><?= e(Lang::get('admin.stock.add_colours')) ?></a></p>
                    <?php } else {
                        foreach ($rows as $byColour) {
                            foreach ($byColour as $cell) { ?>
                                <input type="hidden" name="was[<?= (int) $cell['id'] ?>]" value="<?= e($cell['was']) ?>">
                            <?php }
                        }

                        admin_partial('form/stock-matrix', [
                            'name' => 'stock',
                            'caption' => Lang::get('admin.stock.caption', ['name' => (string) $p['name']]),
                            'colors' => $card['colors'],
                            'sizes' => $card['sizes'],
                            'values' => array_map(static fn (array $r): array => array_map(static fn (array $c): int => (int) $c['stock'], $r), $rows),
                            'cellName' => static fn (int $c, int $s): string => 'stock[' . (int) $rows[$c][$s]['id'] . ']',
                            'cellOff' => static fn (int $c, int $s): bool => !$rows[$c][$s]['active'],
                            'cellAttrs' => static fn (int $c, int $s): array => [
                                'id' => 'stock-' . (int) $rows[$c][$s]['id'],
                                'max' => 9999999,
                                'required' => true,
                                'aria-invalid' => $rows[$c][$s]['error'] !== null ? 'true' : false,
                                'data-low' => (int) $rows[$c][$s]['stock'] <= LOW_STOCK ? ((int) $rows[$c][$s]['stock'] === 0 ? 'out' : 'low') : false,
                            ],
                        ]);

                        $cellErrors = array_filter(array_merge(...array_map(static fn (array $r): array => array_column($r, 'error'), $rows)));
                        foreach ($cellErrors as $message) { ?>
                            <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e($message) ?></span></p>
                        <?php }

                        admin_partial('form/section', [
                            'id' => 'stock-prices-' . $pid,
                            'title' => Lang::get('admin.stock.prices_title'),
                            'help' => Lang::get('admin.stock.prices_help'),
                            'open' => $card['priceErrors'],
                            'body' => static function () use ($card, $rows, $p): void {
                                admin_partial('form/price-matrix', [
                                    'caption' => Lang::get('admin.stock.prices_caption', ['name' => (string) $p['name']]),
                                    'colors' => $card['colors'],
                                    'sizes' => $card['sizes'],
                                    'cells' => array_map(static fn (array $r): array => array_map(static fn (array $c): array => [
                                        'id' => $c['id'], 'price' => $c['price'], 'active' => $c['active'], 'error' => $c['price_error'],
                                    ], $r), $rows),
                                ]);
                                foreach (array_filter(array_merge(...array_map(static fn (array $r): array => array_column($r, 'price_error'), $rows))) as $message) { ?>
                                    <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e($message) ?></span></p>
                                <?php }
                            },
                        ]);
                    } ?>
                </section>
            <?php } ?>
        </form>

        <?php admin_partial('form/save-bar', ['form' => 'stock-form']); ?>

        <?php /* ------------------------------ ready-made designs: a record */ ?>
        <section class="stock-designs" id="stock-designs" aria-labelledby="stock-designs-title" tabindex="-1">
            <h2 class="stock-designs__title" id="stock-designs-title"><?= e(Lang::get('admin.stock.designs_title')) ?></h2>
            <p class="form-field__help"><?= e(Lang::get('admin.stock.designs_note')) ?></p>
            <?php $list = $designs->all();
            admin_partial('data-table', [
                'id' => 'archived-designs',
                'caption' => Lang::get('admin.stock.designs_title'),
                'columns' => [
                    ['key' => 'name', 'label' => Lang::get('admin.stock.col.design'), 'primary' => true],
                    ['key' => 'surcharge', 'label' => Lang::get('admin.stock.col.surcharge'), 'align' => 'end'],
                    ['key' => 'products', 'label' => Lang::get('admin.stock.col.products'), 'align' => 'end'],
                    ['key' => 'status', 'label' => Lang::get('admin.stock.col.status')],
                ],
                'rows' => $list,
                'cell' => static function (array $d, string $key) use ($designs, $public): void {
                    switch ($key) {
                        case 'name':
                            $thumb = (string) ($d['thumb_path'] ?? '');
                            $thumb = $thumb !== '' && !str_contains($thumb, '..') && is_file($public . $thumb) ? $thumb : null; ?>
                            <span class="cell-media">
                                <?php if ($thumb !== null) { ?>
                                    <img class="cell-media__thumb" src="<?= e($thumb) ?>" alt="" width="40" height="40" loading="lazy">
                                <?php } else { ?>
                                    <span class="cell-media__thumb" aria-hidden="true"></span>
                                <?php } ?>
                                <span class="cell-media__text">
                                    <span class="cell-media__title"><bdi><?= e((string) $d['name']) ?></bdi></span>
                                    <bdi class="cell-media__sub code-text" translate="no"><?= e((string) $d['slug']) ?></bdi>
                                </span>
                            </span>
                            <?php break;
                        case 'surcharge':
                            admin_partial('money', ['cents' => (int) $d['surcharge_cents']]);
                            break;
                        case 'products':
                            echo e(Format::number(count($designs->productsFor((int) $d['id']))));
                            break;
                        default:
                            admin_partial('status-chip', ['label' => Lang::get('admin.stock.archived'), 'tone' => 'done']);
                    }
                },
                'empty' => ['text' => Lang::get('admin.stock.designs_none')],
            ]); ?>
        </section>

        <?php /*
         * The design editor, kept in the file and not rendered.
         *
         * Phase 1 of the product rebuild stopped every write to `designs`,
         * `design_products` and `design_layers`. The markup lives in git
         * history (before Phase 3.5) because phase 4 decides what happens to
         * these tables; the write point above refuses the post regardless.
         */ ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
