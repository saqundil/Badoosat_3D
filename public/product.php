<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\ProductService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Garment;
use App\Support\Image;
use App\Support\Shop;

Csrf::session();

$product = ProductService::make()->bySlug(trim((string) ($_GET['slug'] ?? '')));

if ($product === null) {
    http_response_code(404);
}

$errors = $_SESSION['errors'] ?? [];
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['errors'], $_SESSION['notice']);

$title     = ($product['name'] ?? 'غير موجود') . ' — ' . Config::get('app.name');
$current   = 'catalog';
$cartCount = Shop::cartCount();

$returnsNotice = (string) Config::get('shop.returns_notice');

/*
 * A product link is going to be pasted into WhatsApp -- that is the shop's
 * first sales channel, not an afterthought -- so the page carries Open Graph
 * tags with an absolute image URL, and schema.org Product data for search. Both
 * are built from the same rows the page renders, so they cannot drift from what
 * a visitor sees.
 */
$share = null;

if ($product !== null) {
    $base  = rtrim((string) Config::get('app.url'), '/');
    $image = (string) ($product['primary_image']['responsive']['src'] ?? '');

    $share = [
        'url'   => $base . '/product.php?slug=' . rawurlencode((string) $product['slug']),
        'image' => $image === '' ? '' : $base . $image,
        'title' => (string) $product['name'],
        'description' => (string) ($product['summary'] ?: $product['description']),
        'price' => number_format($product['min_price'] / 100, 2, '.', ''),
    ];

    $share['jsonld'] = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $share['title'],
        'description' => $share['description'],
        'sku'         => (string) $product['id'],
        'image'       => $share['image'] === '' ? [] : [$share['image']],
        'brand'       => ['@type' => 'Brand', 'name' => (string) Config::get('app.name')],
        'material'    => (string) ($product['fabric'] ?? ''),
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => $share['url'],
            'price'         => $share['price'],
            'priceCurrency' => 'JOD',
            'availability'  => $product['in_stock']
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
        ],
    ];
}

$garment = $product === null ? null : Garment::make($product['garment'] ?: 'tshirt');
$garmentConfig = $product === null ? null : (Config::file('garments')[$product['garment']] ?? null);
$modelUrl = is_array($garmentConfig) ? ($garmentConfig['model'] ?? null) : null;

/*
 * What the 3D viewer is given.
 *
 * One artwork, at the position the shop set, or none at all. It used to be a
 * map of every ready-made design keyed by id, so the viewer could repaint the
 * shirt as the customer clicked through a picker that no longer exists.
 *
 * The artwork is handed over in the same shape the admin editor uses -- path,
 * side, x, y, scale -- so both draw it through drawDesignLayers() and the
 * customer sees the placement the administrator saved, not an approximation
 * of it.
 */
$modelConfig = $garment === null
    || !(bool) $product['show_3d']
    || !is_string($modelUrl)
    || $modelUrl === '' ? null : [
    'modelUrl'        => $modelUrl,
    'artwork'         => $product['artwork'],
    'garment'         => $garment->toArray(),
    'colors'          => array_column($product['colors'], 'hex', 'id'),
];

$head = static function () use ($share): void {
    if ($share === null) {
        return;
    }
    ?>
    <meta property="og:type" content="product">
    <meta property="og:title" content="<?= e($share['title']) ?>">
    <meta property="og:description" content="<?= e($share['description']) ?>">
    <meta property="og:url" content="<?= e($share['url']) ?>">
    <?php if ($share['image'] !== '') { ?>
        <meta property="og:image" content="<?= e($share['image']) ?>">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="1200">
    <?php } ?>
    <meta property="product:price:amount" content="<?= e($share['price']) ?>">
    <meta property="product:price:currency" content="JOD">
    <meta name="twitter:card" content="summary_large_image">
    <script type="application/ld+json"><?= json_island($share['jsonld']) ?></script>
    <?php
};

/*
 * A product whose price has not been entered yet. It still has a page -- the
 * photographs, the description and the size chart are all real -- but nothing
 * on it may quote a figure, and it cannot be bought until someone sets one.
 * Zero is not a price; it is the column's default.
 */
$hasPrice = (int) ($product['min_price'] ?? 0) > 0;

/*
 * A product is only orderable through a variant: the colour and the size are
 * not columns on the product, they are the thing that is actually in stock,
 * and the price lives there too. With none linked there is nothing to choose
 * and nothing to charge -- which used to render as two headings, "المقاس" and
 * "اللون", with nothing at all underneath them.
 */
$hasVariants = ($product['sizes'] ?? []) !== [] && ($product['colors'] ?? []) !== [];

$body = static function () use (
    $product, $errors, $notice, $modelConfig, $returnsNotice, $hasPrice, $hasVariants
): void {
    if ($product === null) {
        ?>
        <main class="page">
            <h1 class="page__title">لم نعثر على هذا المنتج</h1>
            <p class="page__lede">ربما حُذف أو تغيّر رابطه. <a href="/catalog.php">تصفّح المنتجات</a>.</p>
        </main>
        <?php

        return;
    }

    /*
     * The pill carries a price and nothing else, so it needs its own format
     * rather than money(). The shop-wide helper prints piastres -- right on an
     * invoice, noise inside a badge the size of a thumbnail. Whole dinars
     * here, exactly as the catalogue card prints them.
     */
    $badgePrice = static fn (int $cents): string => number_format($cents / 100, 0, '', ',') . ' د.أ';
    ?>
    <main class="page page--product">
        <div class="pdp">

            <?php /* ------------------------------------------- the top bar */ ?>
            <div class="pdp__topbar">
                <?php /*
                    First in the DOM, so RTL puts it on the right -- and the
                    arrow points that way with it, towards the edge the
                    customer came in from.
                */ ?>
                <a class="pdp__back" href="/catalog.php" aria-label="رجوع إلى المنتجات">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M5 12h14"></path>
                        <path d="m12 5 7 7-7 7"></path>
                    </svg>
                </a>
                <p class="pdp__topbar-title">تفاصيل المنتج</p>
            </div>

            <?php /* ---------------------------------------------- the photo */ ?>
            <?php
            /*
             * One track, one row of slides, one index. The photos and the 3D
             * view are the same kind of thing to the person looking at them --
             * another view of the shirt -- so they are the same kind of thing
             * here: you reach the model by dragging past the last photo, not
             * by finding a tab and losing the photos to it.
             *
             * The order is the one the query returns, which puts the primary
             * image first; nothing re-sorts it here.
             */
            $slides     = $product['images'];
            $has3d      = $modelConfig !== null;
            $modelIndex = count($slides);
            $slideCount = $modelIndex + ($has3d ? 1 : 0);
            $multi      = $slideCount > 1;
            ?>
            <div class="pdp__media-area" data-product-media>
                <div class="pdp__gallery" data-product-gallery
                     data-slide-count="<?= (int) $slideCount ?>">
                    <?php /*
                        The wrapper carries no clip, so the price can sit in
                        the piece the stage's clip removes -- the same
                        arrangement as a catalogue card. The arrows are out
                        here with it for the same reason: anything inside
                        .pdp__media is cut by the notch.
                    */ ?>
                    <div class="pdp__media-wrap" data-pdp-media>
                        <div class="pdp__media">
                            <div class="pdp__track" data-gallery-track>
                                <?php foreach ($slides as $i => $image) { ?>
                                    <div class="pdp__slide" data-slide="<?= (int) $i ?>">
                                        <button type="button" class="pdp__slide-open" data-gallery-open
                                                aria-label="تكبير الصورة <?= (int) $i + 1 ?> من <?= (int) $slideCount ?>">
                                            <img src="<?= e($image['responsive']['src']) ?>"
                                                 <?php if ($image['responsive']['srcset'] !== '') { ?>
                                                     srcset="<?= e($image['responsive']['srcset']) ?>"
                                                     sizes="<?= e($image['responsive']['sizes']) ?>"
                                                 <?php } ?>
                                                 alt="<?= e($product['name']) ?>"
                                                 width="<?= (int) $image['width_px'] ?>"
                                                 height="<?= (int) $image['height_px'] ?>"
                                                 <?php if ($i === 0) { ?>
                                                     loading="eager" fetchpriority="high"
                                                 <?php } else { ?>
                                                     loading="lazy" decoding="async"
                                                 <?php } ?>>
                                        </button>
                                    </div>
                                <?php } ?>

                                <?php if ($slides === [] && !$has3d) { ?>
                                    <div class="pdp__slide" data-slide="0">
                                        <span class="pdp__media-placeholder" aria-hidden="true"><?= e($product['name']) ?></span>
                                    </div>
                                <?php } ?>

                                <?php if ($has3d) { ?>
                                    <?php /*
                                        The scene is not built here and not on
                                        load: product-model-viewer.js waits for
                                        this slide to come into view, and stops
                                        drawing the moment it leaves. A visitor
                                        who never swipes this far never pays
                                        for three.js.
                                    */ ?>
                                    <div class="pdp__slide pdp__slide--model" data-slide="<?= (int) $modelIndex ?>"
                                         data-model-slide>
                                        <div class="pdp__canvas" data-model-canvas></div>

                                        <?php /*
                                            The ring is drawn over the scene
                                            rather than in it: as geometry it
                                            would take lighting, catch the
                                            environment map and have to be
                                            disposed of. It is a shadow, and a
                                            shadow is a drawing.
                                        */ ?>
                                        <svg class="pdp__ring" viewBox="0 0 100 100"
                                             preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                            <ellipse cx="50" cy="78" rx="36" ry="7" fill="none"
                                                     stroke="currentColor" stroke-width="1.5"
                                                     vector-effect="non-scaling-stroke"></ellipse>
                                        </svg>

                                        <button type="button" class="pdp__spin" data-model-spin
                                                aria-label="أدر المجسّم يمينًا أو يسارًا">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                 aria-hidden="true" focusable="false">
                                                <path d="m14 6-6 6 6 6"></path>
                                            </svg>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                 aria-hidden="true" focusable="false">
                                                <path d="m10 6 6 6-6 6"></path>
                                            </svg>
                                        </button>

                                        <p class="pdp__stage-status" data-model-status role="status"
                                           aria-live="polite" hidden></p>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>

                        <?php /*
                            The inner span is what product-media.js measures
                            and scales: the pill around it is a fixed box, so
                            a long price is set smaller rather than made
                            wider. It is also the element product.js rewrites
                            with the live total, which is why the id sits on
                            it and not on the box.
                        */ ?>
                        <?php if ($hasPrice) { ?>
                            <span class="pdp__price"><span class="pdp__price-value" id="price"
                                  data-price aria-live="polite"><?= e($badgePrice((int) $product['min_price'])) ?></span></span>
                        <?php } ?>

                        <?php if ($multi) { ?>
                            <?php /*
                                Desktop only -- a phone has the drag.

                                The sides are logical: previous is at the inline
                                start, which under Arabic right-to-left is the
                                right, where the first slide is. The chevrons
                                below are drawn for that direction -- previous
                                points right -- and the stylesheet mirrors them
                                under [dir='ltr'], so a second language needs no
                                second set of paths here.
                            */ ?>
                            <button type="button" class="pdp__arrow pdp__arrow--prev"
                                    data-gallery-prev aria-label="السابق">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <path d="m10 6 6 6-6 6"></path>
                                </svg>
                            </button>
                            <button type="button" class="pdp__arrow pdp__arrow--next"
                                    data-gallery-next aria-label="التالي">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <path d="m14 6-6 6 6 6"></path>
                                </svg>
                            </button>
                        <?php } ?>
                    </div>

                    <?php if ($multi) { ?>
                        <?php /*
                            Two ways to say the same index: dots on a phone,
                            thumbnails on a desktop. Both are real buttons
                            over the same slide numbers, and only one of them
                            is ever on screen.
                        */ ?>
                        <div class="pdp__dots" data-gallery-dots>
                            <?php foreach ($slides as $i => $_image) { ?>
                                <button type="button" class="pdp__dot" data-goto="<?= (int) $i ?>"
                                        aria-label="الصورة <?= (int) $i + 1 ?>"></button>
                            <?php } ?>
                            <?php if ($has3d) { ?>
                                <button type="button" class="pdp__dot pdp__dot--model"
                                        data-goto="<?= (int) $modelIndex ?>" aria-label="العرض ثلاثي الأبعاد">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="m12 2.6 8 4.6v9.6l-8 4.6-8-4.6V7.2z"></path>
                                        <path d="m4 7.2 8 4.6 8-4.6"></path>
                                        <path d="M12 11.8V21"></path>
                                    </svg>
                                </button>
                            <?php } ?>
                        </div>

                        <div class="pdp__thumbs" data-gallery-thumbs>
                            <?php foreach ($slides as $i => $image) { ?>
                                <button type="button" class="pdp__thumb" data-goto="<?= (int) $i ?>"
                                        aria-label="الصورة <?= (int) $i + 1 ?>">
                                    <img src="<?= e($image['thumbnail']) ?>" alt=""
                                         width="64" height="64" loading="lazy" decoding="async">
                                </button>
                            <?php } ?>
                            <?php if ($has3d) { ?>
                                <button type="button" class="pdp__thumb pdp__thumb--model"
                                        data-goto="<?= (int) $modelIndex ?>" aria-label="العرض ثلاثي الأبعاد">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                                         stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="m12 2.6 8 4.6v9.6l-8 4.6-8-4.6V7.2z"></path>
                                        <path d="m4 7.2 8 4.6 8-4.6"></path>
                                        <path d="M12 11.8V21"></path>
                                    </svg>
                                </button>
                            <?php } ?>
                        </div>
                    <?php } ?>

                    <?php if ($slides !== []) { ?>
                        <dialog class="product-gallery__dialog" data-gallery-dialog
                                aria-label="صورة المنتج بالحجم الكامل">
                            <button type="button" class="product-gallery__close" data-gallery-close
                                    aria-label="إغلاق">×</button>
                            <button type="button" class="product-gallery__zoom" data-gallery-zoom
                                    aria-label="تكبير أو تصغير">+</button>
                            <img data-gallery-full alt="<?= e($product['name']) ?>">
                        </dialog>
                    <?php } ?>
                </div>
            </div>

            <?php /*
                The reading column. It is one element so that the desktop
                grid can place the whole of it beside the photo and make
                it sticky; below 900px it is a plain wrapper with no box
                of its own and the page reads exactly as it did.
            */ ?>
            <div class="pdp__col">
                <?php /* ------------------------------------------------ the name */ ?>
                <h1 class="pdp__name"><?= e($product['name']) ?></h1>

                <?php /*
                    No rating line: the database has no rating column, and the
                    stars that used to sit here were the same five on every
                    product, which is not information. It comes back with reviews.
                */ ?>

                <p class="pdp__saved" id="saved" hidden></p>

                <?php if ($notice !== null) { ?>
                    <p class="alert alert--ok"><?= e($notice) ?></p>
                <?php } ?>

                <?php if ($errors !== []) { ?>
                    <div class="alert alert--error">
                        <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
                    </div>
                <?php } ?>

                <form method="post" action="/cart.php" id="add-form"
                      data-product="<?= (int) $product['id'] ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <?php /* Always a catalogue line. The shop's own artwork is
                             part of the product, so it adds nothing here and
                             needs no field; a customer's artwork arrives from
                             the editor, not from this form. */ ?>
                    <input type="hidden" name="item_type" id="item-type" value="blank">
                    <input type="hidden" name="placement" value="front">

                    <?php if (!$hasVariants) { ?>
                        <?php /*
                            Said plainly, where the choices would have been. An
                            empty "المقاس" with nothing under it reads as a page
                            that failed to load; this reads as a product that is
                            not finished, which is what it is.
                        */ ?>
                        <p class="alert alert--warn">
                            هذا المنتج بلا مقاسات أو ألوان مرتبطة بعد، فلا يمكن طلبه.
                            تُضاف المقاسات والألوان من لوحة التحكم.
                        </p>
                    <?php } else { ?>

                    <?php /* --------------------------------------- the sizes */ ?>
                    <div class="pdp__field">
                        <div class="pdp__field-head">
                            <span class="pdp__field-label">المقاس</span>
                            <button type="button" class="linky" data-sizes-toggle
                                    aria-expanded="false" aria-controls="size-chart">جدول المقاسات</button>
                        </div>

                        <div class="pdp__options">
                            <?php foreach ($product['sizes'] as $i => $size) { ?>
                                <label class="pdp__size">
                                    <input type="radio" name="size_id" value="<?= (int) $size['id'] ?>"
                                           <?= $i === 0 ? 'checked' : '' ?> required>
                                    <span class="pdp__size-text"><?= e($size['code']) ?></span>
                                    <span class="visually-hidden"><?= e($size['label']) ?></span>
                                </label>
                            <?php } ?>
                        </div>

                        <?php /*
                            Actual centimetres, not a letter. The single largest
                            cause of a return is a size guessed from an S, an M
                            or an L that means something different at every shop.
                        */ ?>
                        <table class="size-chart" id="size-chart" hidden>
                            <caption>المقاسات بالسنتيمتر، مقيسة على القطعة مسطّحة.</caption>
                            <thead>
                                <tr><th scope="col">المقاس</th><th scope="col">عرض الصدر</th><th scope="col">الطول</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($product['sizes'] as $size) { ?>
                                    <tr>
                                        <th scope="row"><?= e($size['code']) ?> — <?= e($size['label']) ?></th>
                                        <td><?= e(number_format($size['chest_cm'], 1)) ?> سم</td>
                                        <td><?= e(number_format($size['length_cm'], 1)) ?> سم</td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <?php /* -------------------------------------- the colours */ ?>
                    <div class="pdp__field pdp__field--colors">
                        <span class="pdp__field-label">اللون</span>
                        <div class="pdp__options">
                            <?php foreach ($product['colors'] as $i => $color) { ?>
                                <?php /*
                                    The hex is the only thing handed in from the
                                    row; every rule that draws the disc lives in
                                    the stylesheet and reads it back out of
                                    --swatch. There is no other way to get a
                                    per-product colour onto an element.
                                */ ?>
                                <label class="pdp__color">
                                    <input type="radio" name="color_id" value="<?= (int) $color['id'] ?>"
                                           <?= $i === 0 ? 'checked' : '' ?> required>
                                    <span class="pdp__color-dot" style="--swatch:<?= e($color['hex']) ?>"
                                          title="<?= e($color['name']) ?>"></span>
                                    <span class="visually-hidden"><?= e($color['name']) ?></span>
                                </label>
                            <?php } ?>
                        </div>
                    </div>

                    <?php } ?>

                    <?php /*
                     * No print picker and no placement chooser.
                     *
                     * Both belonged to a model where a shirt and a print were
                     * two choices a customer combined at the till. They are one
                     * thing now: the shop publishes a product, and its artwork
                     * -- if it has any -- is part of what is being sold, at the
                     * position the shop set. A customer choosing where a print
                     * goes was choosing something the shop had not priced.
                     *
                     * Custom artwork does not live here either. It comes from
                     * the editor, and reaches the cart as item_type='custom'
                     * with its own token; this page sells the catalogue.
                     */ ?>

                    <?php /* --------------------------------- the folded details */ ?>
                    <div class="pdp__details">
                        <button type="button" class="pdp__details-toggle" data-details-toggle
                                aria-expanded="false" aria-controls="product-details">
                            <span>عرض تفاصيل المنتج</span>
                            <svg class="pdp__details-arrow" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </button>

                        <div class="pdp__details-body" id="product-details" hidden>
                            <p class="pdp__desc"><?= e($product['description']) ?></p>

                            <dl class="pdp__specs">
                                <?php if (!empty($product['garment_name'])) { ?>
                                    <div><dt>نوع القطعة</dt><dd><?= e($product['garment_name']) ?></dd></div>
                                <?php } ?>
                                <div><dt>طريقة الطباعة</dt><dd><?= e($product['print_method']) ?></dd></div>
                                <?php if ($product['fabric']) { ?>
                                    <div><dt>القماش</dt><dd><?= e($product['fabric']) ?></dd></div>
                                <?php } ?>
                                <?php if ($product['fit']) { ?>
                                    <div><dt>القصّة</dt><dd><?= e($product['fit']) ?></dd></div>
                                <?php } ?>
                                <?php if ($product['care']) { ?>
                                    <div><dt>العناية</dt><dd><?= e($product['care']) ?></dd></div>
                                <?php } ?>
                            </dl>
                        </div>
                    </div>

                    <p class="pdp__hint">خصم تلقائي من ١٠ قطع، ويزيد عند ٢٥ و٥٠.</p>
                    <p class="pdp__hint"><?= e($returnsNotice) ?></p>

                    <?php /* ------------------------------------ the bottom bar */ ?>
                    <div class="pdp__bar">
                        <div class="pdp__qty">
                            <button type="button" data-quantity-change="-1" aria-label="تقليل الكمية">−</button>
                            <input type="number" name="quantity" id="quantity" value="1" min="1" max="200"
                                   required aria-label="الكمية">
                            <button type="button" data-quantity-change="1" aria-label="زيادة الكمية">+</button>
                        </div>

                        <?php /*
                            With no price there is nothing to charge, so the
                            button says so and stays disabled. data-no-price
                            tells product.js to leave it alone: the live price
                            call would otherwise re-enable it on the first
                            colour or size change.
                        */ ?>
                        <?php
                        /*
                         * data-no-price covers both refusals: product.js reads
                         * it as "leave this button alone", and without it the
                         * live price call would re-enable the button on the
                         * first colour or size change -- of which, here, there
                         * are none to make.
                         */
                        $canOrder = $hasPrice && $hasVariants;
                        ?>
                        <button type="submit" class="pdp__add" id="add-button"
                                <?= $canOrder ? '' : 'disabled data-no-price' ?>><?=
                            $canOrder
                                ? 'إضافة إلى السلة'
                                : (!$hasVariants ? 'غير متاح للطلب' : 'السعر غير متوفر') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <?php if ($modelConfig !== null) { ?>
        <script type="application/json" id="product-model-config"><?= json_island($modelConfig) ?></script>
        <script type="module" src="/assets/js/product-model-viewer.js?v=<?= e(ASSETS) ?>"></script>
    <?php } ?>

    <?php if ($product['images'] !== []) { ?>
        <script type="module" src="/assets/js/product-gallery.js?v=<?= e(ASSETS) ?>"></script>
    <?php } ?>
    <script type="module" src="/assets/js/product-media.js?v=<?= e(ASSETS) ?>"></script>
    <script type="module" src="/assets/js/product.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

if ($modelConfig !== null) {
    $importmap = true;
    $hasHero = false;
    $heroScript = false;
}

require dirname(__DIR__) . '/src/View/layout.php';
