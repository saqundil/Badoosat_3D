<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\GarmentService;
use App\Service\ProductManagementService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\Lang;

Auth::require();
Csrf::session();

/*
 * The product list (template C). Paged on the server, so sorting is too: a
 * column header is a link that keeps the search and goes back to page one.
 */

/** Products per page of the list. */
const PRODUCTS_PER_PAGE = 20;

$service = ProductManagementService::make();
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = in_array($_GET['sort'] ?? '', ['name', 'price', 'updated'], true) ? (string) $_GET['sort'] : 'updated';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';

$result = $service->paginate($search, $page, PRODUCTS_PER_PAGE, $sort, $dir);
$facts = $service->listFacts(array_map('intval', array_column($result['items'], 'id')));

$locale = Lang::locale();
$types = [];
foreach (GarmentService::make()->all() as $type) {
    $types[(int) $type['id']] = (string) ($locale === 'en' && trim((string) $type['name_en']) !== '' ? $type['name_en'] : $type['name_ar']);
}

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);

$title = Lang::get('admin.products.title');
$current = 'products';
$toast = $notice !== null ? ['text' => $notice] : null;

$body = static function () use ($result, $facts, $types, $search, $sort, $dir): void {
    $public = dirname(__DIR__);
    // A stored path whose file is gone shows the empty frame, not a broken image.
    $thumb = static fn (array $p, array $f): ?string => array_values(array_filter(
        [(string) ($p['image_path'] ?? ''), (string) ($f['thumb'] ?? '')],
        static fn (string $path): bool => $path !== '' && !str_contains($path, '..') && is_file($public . $path)
    ))[0] ?? null;
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.products.title'),
            'subtitle' => Lang::get('admin.products.subtitle'),
            'actions'  => static function (): void { ?>
                <a class="button button--primary" href="/admin/product-edit.php">
                    <?= admin_icon('plus') ?><?= e(Lang::get('admin.products.new')) ?>
                </a>
            <?php },
        ]); ?>

        <?php admin_partial('filter-bar', [
            'action'    => '/admin/products.php',
            'label'     => Lang::get('admin.products.filter_label'),
            'keep'      => $sort === 'updated' && $dir === 'desc' ? [] : ['sort' => $sort, 'dir' => $dir],
            'clearHref' => admin_url(['q' => null, 'page' => null]),
            'fields'    => [
                ['type' => 'search', 'name' => 'q', 'label' => Lang::get('admin.filters.search'), 'value' => $search,
                 'placeholder' => Lang::get('admin.products.search_hint')],
            ],
        ]); ?>

        <?php admin_partial('data-table', [
            'id'       => 'products',
            'caption'  => Lang::get('admin.products.title'),
            'columns'  => [
                ['key' => 'name', 'label' => Lang::get('admin.products.col.product'), 'primary' => true, 'sortable' => true],
                ['key' => 'type', 'label' => Lang::get('admin.products.col.type')],
                ['key' => 'colours', 'label' => Lang::get('admin.products.col.colours')],
                ['key' => 'stock', 'label' => Lang::get('admin.products.col.stock'), 'align' => 'end'],
                ['key' => 'price', 'label' => Lang::get('admin.products.col.price'), 'align' => 'end', 'sortable' => true],
                ['key' => 'features', 'label' => Lang::get('admin.products.col.features')],
                ['key' => 'status', 'label' => Lang::get('admin.products.col.status')],
            ],
            'rows'     => $result['items'],
            'rowLabel' => static fn (array $p): string => (string) $p['name'],
            'sort'     => $sort === 'updated' ? null : $sort,
            'sortDir'  => $dir,
            'sortHref' => static fn (string $key, string $next): string => admin_url(['sort' => $key, 'dir' => $next, 'page' => null]),
            'cell'     => static function (array $p, string $key) use ($facts, $types, $thumb): void {
                $f = $facts[(int) $p['id']] ?? ['colors' => [], 'stock' => 0, 'variants' => 0, 'thumb' => null];
                switch ($key) {
                    case 'name':
                        $src = $thumb($p, $f); ?>
                        <span class="cell-media">
                            <?php if ($src !== null) { ?>
                                <img class="cell-media__thumb" src="<?= e($src) ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
                            <?php } else { ?>
                                <span class="cell-media__thumb" aria-hidden="true"></span>
                            <?php } ?>
                            <span class="cell-media__text">
                                <a class="cell-media__title" href="/admin/product-edit.php?id=<?= (int) $p['id'] ?>"><bdi><?= e((string) $p['name']) ?></bdi></a>
                                <bdi class="cell-media__sub code-text" translate="no"><?= e((string) $p['slug']) ?></bdi>
                            </span>
                        </span>
                        <?php break;
                    case 'type':
                        $type = $types[(int) $p['garment_id']] ?? null; ?>
                        <?php if ($type !== null) { ?><bdi><?= e($type) ?></bdi><?php } else { ?><span class="cell-media__sub"><?= e(Lang::get('admin.products.no_type')) ?></span><?php } ?>
                        <span class="cell-media__sub"><?= e(Lang::get('admin.products.kind.' . ($p['presentation_type'] === 'designed' ? 'printed' : 'plain'))) ?></span>
                        <?php break;
                    case 'colours': ?>
                        <span class="swatch-row" role="img" aria-label="<?= e(Format::plural('admin.garments.colour_count', count($f['colors']))) ?>">
                            <?php foreach (array_slice($f['colors'], 0, 8) as $c) { ?>
                                <span class="swatch swatch--on-light" style="--swatch: <?= e($c['hex']) ?>" title="<?= e($c['name']) ?>"></span>
                            <?php } ?>
                            <?php if (count($f['colors']) > 8) { ?><span class="swatch-row__more">+<?= e(Format::number(count($f['colors']) - 8)) ?></span><?php } ?>
                            <?php if ($f['colors'] === []) { ?><span class="cell-media__sub"><?= e(Lang::get('admin.garments.none')) ?></span><?php } ?>
                        </span>
                        <?php break;
                    case 'stock': ?>
                        <?= e(Format::number($f['stock'])) ?>
                        <span class="cell-media__sub"><?= e(Format::plural('admin.products.variant_count', $f['variants'])) ?></span>
                        <?php break;
                    case 'price':
                        admin_partial('money', ['cents' => (int) $p['base_price_cents']]);
                        break;
                    case 'features': ?>
                        <span class="tag-row">
                            <?php if ((int) $p['show_3d']) { ?><span class="tag"><?= e(Lang::get('admin.products.tag.3d')) ?></span><?php } ?>
                            <?php if ((int) $p['customization_enabled']) { ?><span class="tag"><?= e(Lang::get('admin.products.tag.custom')) ?></span><?php } ?>
                            <?php if (!(int) $p['show_3d'] && !(int) $p['customization_enabled']) { ?><span class="cell-media__sub"><?= e(Lang::get('admin.garments.none')) ?></span><?php } ?>
                        </span>
                        <?php break;
                    default:
                        // On sale needs the product on and at least one active variant.
                        $state = !(int) $p['is_active'] ? 'hidden' : ($f['variants'] === 0 ? 'unsellable' : ($f['stock'] === 0 ? 'out' : 'live'));
                        admin_partial('status-chip', [
                            'label' => Lang::get('admin.products.status.' . $state),
                            'tone'  => ['live' => 'active', 'out' => 'stopped', 'unsellable' => 'stopped', 'hidden' => 'done'][$state],
                        ]);
                }
            },
            'actions' => static function (array $p): void { ?>
                <a class="menu-item" href="/admin/product-edit.php?id=<?= (int) $p['id'] ?>"><?= e(Lang::get('admin.products.edit')) ?></a>
                <a class="menu-item" href="/admin/catalog.php"><?= e(Lang::get('admin.products.stock')) ?></a>
                <a class="menu-item" href="/product.php?slug=<?= e(rawurlencode((string) $p['slug'])) ?>" target="_blank" rel="noopener"><?= e(Lang::get('admin.products.view')) ?></a>
            <?php },
            'empty' => $search !== ''
                ? ['text' => Lang::get('admin.products.empty_search', ['q' => $search]), 'action' => ['href' => admin_url(['q' => null, 'page' => null]), 'text' => Lang::get('admin.filters.clear')]]
                : ['text' => Lang::get('admin.products.empty'), 'hint' => Lang::get('admin.products.empty_hint'),
                   'action' => ['href' => '/admin/product-edit.php', 'text' => Lang::get('admin.products.new')]],
            'pagination' => [
                'page'    => $result['page'],
                'pages'   => $result['pages'],
                'href'    => static fn (int $n): string => admin_url(['page' => $n > 1 ? $n : null]),
                'summary' => $result['total'] === 0 ? null : Lang::get('admin.table.showing', [
                    'first' => Format::number(($result['page'] - 1) * PRODUCTS_PER_PAGE + 1),
                    'last'  => Format::number(($result['page'] - 1) * PRODUCTS_PER_PAGE + count($result['items'])),
                    'total' => Format::number($result['total']),
                ]),
            ],
        ]); ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
