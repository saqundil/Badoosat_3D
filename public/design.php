<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\GarmentService;
use App\Service\ProductService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Garment;
use App\Support\GarmentModels;
use App\Support\Shop;
use App\Support\UploadLimit;

Csrf::session();

/*
 * The customer's design editor.
 *
 * This is the one door to custom artwork. The catalogue sells what the shop
 * published; this page is where a customer brings their own.
 *
 * The flow it serves: pick a cut, pick a colour and a size, upload artwork and
 * place it on the model, add it to the cart. The editor bundle does the middle
 * of that -- it is a React and three.js island that mounts into #tshirt-editor,
 * reads its whole configuration out of #editor-config, and when the customer is
 * done posts the design to /api/design.php and then submits the cart line
 * itself.
 *
 * WHAT A CUT NEEDS TO APPEAR HERE
 *
 * The island is product-first: its config requires a product_id and a list of
 * variants, because a cart line points at a variant and a variant carries the
 * price and the stock. A garment type carries neither. So each type names a
 * base product -- what a custom order of that cut is sold as -- and a type
 * without a usable one is left out of the chooser entirely rather than offered
 * and then refused. The admin types screen says which types those are and why.
 */

$service = GarmentService::make();
$catalog = ProductService::make();

$types = $service->editorReady();

/* Which cut the customer is on. The first ready one unless they picked. */
$requested = (int) ($_GET['garment'] ?? 0);
$typeIds = array_map('intval', array_column($types, 'id'));
$garmentId = in_array($requested, $typeIds, true) ? $requested : (int) ($typeIds[0] ?? 0);

/*
 * Asked for a cut that is not on offer.
 *
 * Falling back to the first available one is right -- a working editor beats an
 * error page -- but doing it silently is not: a customer who followed a link to
 * a particular cut would be drawing on a different garment without being told.
 * They came from somewhere that offered it, so it was available and now is not:
 * its base product was deactivated, or its last variant was.
 */
$switchedAway = $requested > 0 && $requested !== $garmentId;

$type = null;
$editorConfig = null;
$geometryKey = '';

foreach ($types as $candidate) {
    if ((int) $candidate['id'] === $garmentId) {
        $type = $candidate;
    }
}

if ($type !== null) {
    $base = $service->baseProductFor($garmentId);
    $product = $base === null ? null : $catalog->find((int) $base['id']);

    if ($product !== null) {
        /*
         * The geometry, reached the same way everything else reaches it: the
         * type names a model, the registry names the measured entry. The key
         * travels with every request the island makes, so the press file is
         * checked against the cut it was drawn on.
         */
        $geometryKey = GarmentModels::geometryFor((string) $type['model_key']);
        $garment = Garment::make($geometryKey);

        /*
         * Colours and sizes are the intersection of two things: what the cut is
         * made in, and what the base product actually has variants for. The
         * island filters again on stock, so a colour with nothing on the shelf
         * disappears by itself.
         */
        $typeColorIds = $service->colorIdsFor($garmentId);
        $typeSizeIds = $service->sizeIdsFor($garmentId);

        $variants = [];

        foreach ($product['variants'] as $key => $variant) {
            [$colorId, $sizeId] = array_map('intval', explode(':', (string) $key));

            if (!in_array($colorId, $typeColorIds, true) || !in_array($sizeId, $typeSizeIds, true)) {
                continue;
            }

            $variants[] = [
                'id' => (int) $variant['id'],
                'color_id' => $colorId,
                'size_id' => $sizeId,
                'price_cents' => (int) $variant['price_cents'],
                'stock' => (int) $variant['stock'],
            ];
        }

        $offeredColors = array_column($variants, 'color_id');
        $offeredSizes = array_column($variants, 'size_id');

        $editorConfig = [
            'product_id' => (int) $product['id'],
            'garment_key' => $geometryKey,
            'variants' => $variants,
            'colors' => array_values(array_filter(
                $product['colors'],
                static fn (array $color): bool => in_array((int) $color['id'], $offeredColors, true)
            )),
            'sizes' => array_values(array_filter(
                $product['sizes'],
                static fn (array $size): bool => in_array((int) $size['id'], $offeredSizes, true)
            )),
            'model_url' => GarmentModels::pathFor((string) $type['model_key']),
            'csrf_token' => Csrf::token(),
            'max_upload_mb' => (int) UploadLimit::mebibytes(UploadLimit::bytes()),
            /* The cut travels with the design so the server checks the press
               file against the geometry it was drawn on. */
            'endpoint' => '/api/design.php?garment=' . rawurlencode($geometryKey),
            'returns_notice' => (string) Config::get('shop.returns_notice', ''),
            'garment' => $garment->toArray(),
        ];
    }
}

$title = 'صمّم التيشيرت الخاص بك — ' . Config::get('app.name');
/* layout.php marks the current link by destination, not by this name. */
$current = '/design.php';
$cartCount = Shop::cartCount();

$body = static function () use ($types, $type, $garmentId, $editorConfig, $switchedAway): void {
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <div>
                <h1 class="page__title">صمّم التيشيرت الخاص بك</h1>
                <p class="page__lede">
                    اختر القصّة واللون والمقاس، ارفع تصميمك وضعه على القطعة، ثم أضفه إلى السلة.
                </p>
            </div>
        </div>

        <?php if ($types === []) { ?>
            <?php /*
             * Nothing to design on.
             *
             * Said as a state of the shop rather than as an error, because that
             * is what it is: no cut has a base product set yet. The customer
             * gets a way onward instead of a dead end.
             */ ?>
            <p class="alert alert--error">
                لا توجد قصّة متاحة للتصميم المخصّص حاليًا.
                <a href="/catalog.php">تصفّح المنتجات الجاهزة</a> أو عد لاحقًا.
            </p>
            <?php return; ?>
        <?php } ?>

        <?php if ($switchedAway) { ?>
            <?php /* The cut they asked for went away -- its base product was
                     deactivated, or its last variant was. Said plainly, with
                     the editor still usable on a cut that works. */ ?>
            <p class="alert alert--notice">
                القصّة التي طلبتها لم تعد متاحة للتصميم المخصّص، فعرضنا لك قصّة أخرى.
                يمكنك اختيار غيرها من القائمة.
            </p>
        <?php } ?>

        <?php if ($editorConfig === null) { ?>
            <?php /* The chosen cut lost its base product between the list and
                     here. A sentence, not a broken screen. */ ?>
            <p class="alert alert--error">
                هذه القصّة غير متاحة للتصميم الآن. اختر قصّة أخرى من القائمة أو
                <a href="/catalog.php">تصفّح المنتجات الجاهزة</a>.
            </p>
        <?php } ?>

        <?php if (count($types) > 1) { ?>
            <form class="design-picker" method="get" action="/design.php">
                <label class="field">
                    <span>القصّة</span>
                    <select name="garment" onchange="this.form.submit()">
                        <?php foreach ($types as $option) { ?>
                            <option value="<?= (int) $option['id'] ?>"
                                <?= (int) $option['id'] === $garmentId ? ' selected' : '' ?>>
                                <?= e($option['name_ar']) ?>
                            </option>
                        <?php } ?>
                    </select>
                    <noscript><button type="submit" class="button">اعرض</button></noscript>
                </label>
            </form>
        <?php } elseif ($type !== null) { ?>
            <p class="hint">القصّة: <strong><?= e($type['name_ar']) ?></strong></p>
        <?php } ?>

        <?php if ($editorConfig !== null) { ?>
            <?php /*
                The bundle's URL carries no ?v=. editor-scene.js imports "./editor.js"
                by its bare name, and a module is identified by its full URL: launching
                editor.js?v=N as well loaded the entry twice, two Reacts met in one
                tree and the island mounted blank. Freshness after a deploy comes from
                public/.htaccess, which has these files revalidated on every load.
                The stylesheet is a plain <link> and keeps its version.
            */ ?>
            <div class="design-launch">
                <button type="button" class="button button--primary"
                        data-editor-launch="/assets/editor/editor.js"
                        data-editor-styles="/assets/editor/editor.css?v=<?= e(ASSETS) ?>">
                    افتح المحرّر
                </button>

                <p class="hint" id="editor-error" hidden>
                    تعذّر تحميل المحرّر. تحقّق من اتصالك ثم أعد المحاولة، أو
                    <a href="/upload.php">ارفع ملفًا جاهزًا للطباعة</a>.
                </p>
            </div>

            <div class="design-loading" id="editor-loading" hidden>
                <p>جارٍ تحميل المحرّر…</p>
            </div>

            <?php /* The island mounts here. It is empty until the bundle lands:
                     naming that bundle in a <script src> would download React,
                     three.js and Fabric for every visitor who only came to
                     look. */ ?>
            <div id="tshirt-editor"></div>

            <script type="application/json" id="editor-config"><?= json_island($editorConfig) ?></script>
        <?php } ?>

        <?php /*
         * The second door, kept.
         *
         * A company with a print-ready file does not want to be walked through
         * a 3D scene, and upload.php takes a quantity grid across sizes in one
         * go -- which the editor does not do. It is unlinked everywhere else;
         * this is its entrance until the editor covers that case too.
         */ ?>
        <div class="design-alt">
            <h2>لديّ ملف جاهز للطباعة</h2>
            <p class="hint">
                إن كان تصميمك جاهزًا بدقّة الطباعة ولا تحتاج إلى وضعه على المجسم،
                ارفعه مباشرة واطلب عدّة مقاسات دفعة واحدة.
            </p>
            <a class="button" href="/upload.php">ارفع ملفًا جاهزًا</a>
        </div>
    </main>

    <?php if ($editorConfig !== null) { ?>
        <?php /* A kilobyte. It fetches the island by import() on the first
                 click and never before, which is the whole point of it being a
                 separate file. */ ?>
        <script type="module" src="/assets/js/editor-launch.js?v=<?= e(ASSETS) ?>"></script>
    <?php } ?>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
