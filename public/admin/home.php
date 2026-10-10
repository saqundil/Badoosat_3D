<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HomeSettingsService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Format;
use App\Support\Lang;
use App\Support\UploadLimit;

Auth::require();
Csrf::session();

/*
 * The landing page's own screen (template E): the two category photographs, the
 * banner over the design editor, and which products are trending.
 *
 * WHY THE TRENDING PICKER IS ON THIS PAGE AND NOT ON THE PRODUCT FORM
 *
 * Because trending is a decision about the home page, not about a product. Ticking
 * a box on a product form tells you nothing about how many other products are
 * ticked or what order they will appear in -- and "eight at most, in this order" is
 * exactly the thing the owner is deciding. One screen showing all of them together
 * is the screen where that decision can actually be made.
 *
 * ONE SAVE
 *
 * The pictures, the wording and the trending list used to be two forms with a
 * Save button each. They are one form now, with the dashboard's save bar, and
 * home-save.php writes nothing until all of it has been checked.
 *
 * stored() rather than settings(): the form must show an empty field as empty. If
 * it read the page's own getter it would print the fallback text into the input,
 * and the next save would write the default into the database as though the owner
 * had typed it.
 */
$service  = HomeSettingsService::make();
$settings = $service->stored();

$errors = $_SESSION['home_errors'] ?? [];
$old    = $_SESSION['home_old'] ?? null;
unset($_SESSION['home_errors'], $_SESSION['home_old']);

/* A refused save comes back with what was typed, not with what is stored. */
if (is_array($old)) {
    foreach (['promo_title', 'promo_text', 'promo_button'] as $key) {
        $settings[$key] = (string) ($old[$key] ?? $settings[$key]);
    }
}

/*
 * The product list for the picker, read straight from the table.
 *
 * Not through ProductService: its listing is cached and shaped for a shop front --
 * active products only, with variants and images folded in. This screen needs the
 * inactive ones too, because a product deactivated while still marked trending is
 * precisely the state the owner needs to see and fix.
 */
$products = Db::pdo()->query(
    'SELECT p.id, p.name, p.slug, p.is_active, p.is_trending, p.trending_order, p.image_path,
            g.name_ar AS garment_name_ar, g.name_en AS garment_name_en
       FROM products p
  LEFT JOIN garments g ON g.id = p.garment_id
   ORDER BY p.is_trending DESC, p.trending_order ASC, p.name ASC'
)->fetchAll();

/* What the form shows: the ticks that were posted, or the ones that are stored. */
$ticked = is_array($old)
    ? array_map('intval', (array) ($old['trending'] ?? []))
    : array_column(array_filter($products, static fn (array $p): bool => (int) $p['is_trending'] === 1), 'id');
$ticked = array_map('intval', $ticked);
$orders = is_array($old) ? (array) ($old['order'] ?? []) : [];

$chosen  = count($ticked);
$unusable = count(array_filter(
    $products,
    static fn (array $p): bool => in_array((int) $p['id'], $ticked, true) && (int) $p['is_active'] !== 1
));

$notice = match (true) {
    isset($_GET['saved'])    => Lang::get('admin.home.saved'),
    isset($_GET['trending']) => Lang::get('admin.home.saved'),
    default                  => null,
};

$locale = Lang::locale();
$public = (string) Config::get('app.paths.public');
$exists = static fn (string $path): bool => $path !== '' && !str_contains($path, '..') && is_file($public . $path);

/** The three image fields, so the markup below is one loop rather than three. */
$images = [
    'printed_card_image' => ['4 / 5', 'printed'],
    'plain_card_image'   => ['4 / 5', 'plain'],
    'promo_image'        => ['16 / 6', 'promo'],
];

$title   = Lang::get('admin.home.title');
$current = 'home';
$toast   = $notice !== null ? ['text' => $notice] : null;

$body = static function () use (
    $settings, $images, $products, $ticked, $orders, $chosen, $unusable, $errors, $exists, $locale
): void {
    $err = static fn (string $key): ?string => $errors[$key] ?? null;
    $defaults = HomeSettingsService::DEFAULTS;
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.home.title'),
            'subtitle' => Lang::get('admin.home.subtitle'),
            'actions'  => static function (): void { ?>
                <a class="button button--secondary" href="/" target="_blank" rel="noopener">
                    <?= admin_icon('external') ?><?= e(Lang::get('admin.home.view')) ?>
                </a>
            <?php },
        ]); ?>

        <?php admin_partial('form/error-summary', [
            'errors' => $errors,
            'ids'    => ['images' => 'f-printed-card-image', 'trending' => 'home-trending'],
        ]); ?>

        <form id="home-form" method="post" action="/admin/home-save.php" enctype="multipart/form-data" data-watch>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= UploadLimit::bytes() ?>">

            <?php admin_partial('settings', [
                'label' => Lang::get('admin.home.sections'),
                'sections' => [
                    'home-images' => [
                        'title' => Lang::get('admin.home.s.images'),
                        'help'  => Lang::get('admin.home.s.images_help', ['mb' => UploadLimit::mebibytes(UploadLimit::bytes())]),
                        'body'  => static function () use ($images, $settings, $exists, $err): void { ?>
                            <div class="form-grid">
                                <?php foreach ($images as $key => [$ratio, $slug]) {
                                    $current = (string) $settings[$key];
                                    admin_partial('form/dropzone', [
                                        'name'       => $key,
                                        'label'      => Lang::get('admin.home.f.' . $slug),
                                        'current'    => $exists($current) ? $current : null,
                                        'removeName'  => 'clear[]',
                                        'removeValue' => $key,
                                        'removeShow'  => $current !== '',
                                        'ratio'      => $ratio,
                                        'accept'     => 'image/jpeg,image/png,image/webp',
                                        'help'       => Lang::get('admin.home.f.' . $slug . '_help')
                                            . ($current !== '' && !$exists($current) ? ' ' . Lang::get('admin.home.file_missing') : ''),
                                        'error'      => $err('images'),
                                    ]);
                                } ?>
                            </div>
                        <?php },
                    ],

                    'home-promo' => [
                        'title' => Lang::get('admin.home.s.promo'),
                        'help'  => Lang::get('admin.home.s.promo_help'),
                        'body'  => static function () use ($settings, $defaults): void { ?>
                            <div class="promo-editor">
                                <div class="form-stack">
                                    <?php admin_partial('form/field', [
                                        'type' => 'text', 'name' => 'promo_title', 'label' => Lang::get('admin.home.f.promo_title'),
                                        'value' => (string) $settings['promo_title'], 'maxlength' => 120, 'dir' => 'auto',
                                        'attrs' => ['placeholder' => $defaults['promo_title'], 'data-promo' => 'title'],
                                    ]); ?>
                                    <?php admin_partial('form/field', [
                                        'type' => 'textarea', 'name' => 'promo_text', 'label' => Lang::get('admin.home.f.promo_text'),
                                        'value' => (string) $settings['promo_text'], 'maxlength' => 400, 'dir' => 'auto',
                                        'attrs' => ['placeholder' => $defaults['promo_text'], 'rows' => 3, 'data-promo' => 'text'],
                                    ]); ?>
                                    <?php admin_partial('form/field', [
                                        'type' => 'text', 'name' => 'promo_button', 'label' => Lang::get('admin.home.f.promo_button'),
                                        'value' => (string) $settings['promo_button'], 'maxlength' => 40, 'dir' => 'auto',
                                        'attrs' => ['placeholder' => $defaults['promo_button'], 'data-promo' => 'button'],
                                    ]); ?>
                                    <p class="form-field__help"><?= e(Lang::get('admin.home.promo_default_help')) ?></p>
                                </div>

                                <?php /* The banner as the visitor meets it, with the
                                         defaults standing in for anything left empty. */ ?>
                                <figure class="promo-preview" aria-labelledby="promo-preview-title">
                                    <figcaption class="form-field__label" id="promo-preview-title"><?= e(Lang::get('admin.home.preview')) ?></figcaption>
                                    <div class="promo-card" data-promo-card>
                                        <p class="promo-card__title" data-promo-out="title"><bdi><?= e((string) ($settings['promo_title'] ?: $defaults['promo_title'])) ?></bdi></p>
                                        <p class="promo-card__text" data-promo-out="text"><bdi><?= e((string) ($settings['promo_text'] ?: $defaults['promo_text'])) ?></bdi></p>
                                        <span class="promo-card__button" data-promo-out="button"><bdi><?= e((string) ($settings['promo_button'] ?: $defaults['promo_button'])) ?></bdi></span>
                                    </div>
                                </figure>
                            </div>
                        <?php },
                    ],

                    'home-trending' => [
                        'title' => Lang::get('admin.home.s.trending'),
                        'help'  => Lang::get('admin.home.s.trending_help'),
                        'badge' => ['text' => Lang::get('admin.home.chosen', ['n' => Format::number($chosen), 'max' => Format::number(8)]),
                                    'kind' => $chosen > 8 ? 'stopped' : 'active'],
                        'body'  => static function () use ($products, $ticked, $orders, $unusable, $err, $exists, $locale): void {
                            if ($products === []) {
                                admin_partial('state', ['kind' => 'empty', 'text' => Lang::get('admin.products.empty'),
                                    'action' => ['href' => '/admin/product-edit.php', 'text' => Lang::get('admin.products.new')]]);

                                return;
                            }

                            if ($unusable > 0) { ?>
                                <p class="alert alert--info"><?= admin_icon('alert') ?> <?= e(Format::plural('admin.home.unusable', $unusable)) ?></p>
                            <?php }

                            if ($err('trending') !== null) { ?>
                                <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e((string) $err('trending')) ?></span></p>
                            <?php } ?>

                            <ul class="trending-list" data-trending data-msg-limit="<?= e(Lang::get('admin.home.limit_reached', ['max' => Format::number(8)])) ?>">
                                <?php foreach ($products as $product) {
                                    $id = (int) $product['id'];
                                    $on = in_array($id, $ticked, true);
                                    $thumb = (string) ($product['image_path'] ?? '');
                                    $type = (string) ($locale === 'en' && trim((string) $product['garment_name_en']) !== '' ? $product['garment_name_en'] : (string) $product['garment_name_ar']);
                                    $order = (int) ($orders[$id] ?? $product['trending_order']); ?>
                                    <li class="trending-row<?= $on ? ' is-on' : '' ?>">
                                        <label class="check check--bare">
                                            <input type="checkbox" name="trending[]" value="<?= $id ?>"<?= $on ? ' checked' : '' ?>
                                                   aria-label="<?= e(Lang::get('admin.home.pick', ['name' => $product['name']])) ?>">
                                            <span class="check__box" aria-hidden="true"></span>
                                        </label>

                                        <?php if ($exists($thumb)) { ?>
                                            <img class="cell-media__thumb" src="<?= e($thumb) ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
                                        <?php } else { ?>
                                            <span class="cell-media__thumb" aria-hidden="true"></span>
                                        <?php } ?>

                                        <span class="cell-media__text">
                                            <a class="cell-media__title" href="/admin/product-edit.php?id=<?= $id ?>"><bdi><?= e((string) $product['name']) ?></bdi></a>
                                            <span class="cell-media__sub">
                                                <?php if ($type !== '') { ?><bdi><?= e($type) ?></bdi><?php } ?>
                                                <?php if ((int) $product['is_active'] !== 1) { ?>
                                                    · <span class="trending-row__warn"><?= e(Lang::get('admin.home.inactive')) ?></span>
                                                <?php } ?>
                                            </span>
                                        </span>

                                        <label class="trending-row__order">
                                            <span class="form-field__help"><?= e(Lang::get('admin.home.order')) ?></span>
                                            <input class="input input--cell" type="number" name="order[<?= $id ?>]" value="<?= $order ?>"
                                                   min="0" max="999" step="1" inputmode="numeric"
                                                   aria-label="<?= e(Lang::get('admin.home.order_of', ['name' => $product['name']])) ?>">
                                        </label>
                                    </li>
                                <?php } ?>
                            </ul>
                        <?php },
                    ],
                ],
            ]); ?>
        </form>

        <?php admin_partial('form/save-bar', ['form' => 'home-form']); ?>
    </main>

    <script type="module" src="/assets/js/admin-home.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
