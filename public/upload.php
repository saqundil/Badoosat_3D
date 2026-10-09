<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Repository\ProductRepository;
use App\Service\CartService;
use App\Service\CustomDesignService;
use App\Service\PricingService;
use App\Service\ProductService;
use App\Service\ValidationException;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\RateLimiter;
use App\Support\Response;
use App\Support\Shop;
use App\Support\UploadLimit;

Csrf::session();

/*
 * The door for a company, a team or an event.
 *
 * These are the largest orders the shop takes and the least tolerant of being
 * made to work for it: somebody with a print-ready file and a list of sizes
 * does not want to be walked through a 3D editor to place artwork they have
 * already placed. Three fields, a quantity grid, and out.
 *
 * Everything it produces is a custom_designs row of exactly the shape the
 * editor writes -- same table, same columns, same resolution check -- so the
 * cart, the queue, the press and the admin screen cannot tell the two apart.
 */

$products = ProductService::make();
$catalog  = $products->catalog('newest');
$repo     = ProductRepository::make();
$sizes    = $repo->sizes();
$colors   = $repo->colors();

$errors = [];
$notice = null;

$selectedProduct = (int) ($_POST['product_id'] ?? ($catalog['items'][0]['id'] ?? 0));

if (UploadLimit::postBodyExceeded()) {
    $errors['artwork'] = 'حجم الطلب أكبر من المسموح. ' . UploadLimit::message();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    if (!RateLimiter::make()->check('upload', RateLimiter::client())) {
        $errors['rate'] = 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.';
    } else {
        try {
            // The design first: one row, however many garments carry it.
            $design = CustomDesignService::make()->storeUpload($_POST, $_FILES);

            /*
             * The quantity grid, as sent. Ten pieces across five sizes become
             * five cart lines that all point at the one custom_design_id -- so
             * the queue makes one press file for the lot, which is what the
             * shop floor actually needs.
             */
            $rows = [];

            foreach ((array) ($_POST['qty'] ?? []) as $key => $quantity) {
                [$colorId, $sizeId] = array_pad(explode(':', (string) $key, 2), 2, '0');

                if ((int) $quantity > 0) {
                    $rows[] = [
                        'color_id' => (int) $colorId,
                        'size_id'  => (int) $sizeId,
                        'quantity' => (int) $quantity,
                    ];
                }
            }

            $cart   = Shop::cart();
            $pieces = CartService::make()->addSpread($cart['id'], [
                'item_type'    => 'custom',
                'product_id'   => (int) $_POST['product_id'],
                'placement'    => (string) ($_POST['placement'] ?? 'front'),
                'custom_token' => $design['design_token'],
            ], $rows);

            $_SESSION['notice'] = 'أُضيفت ' . $pieces . ' قطعة إلى السلة بتصميم واحد.';
            Response::redirect('/cart.php');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $selectedProduct = (int) ($_POST['product_id'] ?? 0);
        }
    }
}

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);

$title     = 'ارفع تصميمك — ' . Config::get('app.name');
$current   = 'design';
$cartCount = Shop::cartCount();

$tiers = Config::get('shop.quantity_discount');
ksort($tiers);

$body = static function () use ($catalog, $sizes, $colors, $errors, $notice, $selectedProduct, $tiers): void {
    ?>
    <main class="page page--narrow">
        <div class="page__head">
            <div>
                <h1 class="page__title">ارفع تصميمك</h1>
                <p class="page__lede">
                    عندك ملف جاهز؟ ارفعه، حدّد الكميات والمقاسات، وأضفه للسلة —
                    بلا محرّر وبلا حساب. للفرق والشركات والمناسبات.
                </p>
            </div>
        </div>

        <?php if ($notice !== null) { ?>
            <p class="alert alert--ok"><?= e($notice) ?></p>
        <?php } ?>

        <?php if ($errors !== []) { ?>
            <div class="alert alert--error">
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        <?php } ?>

        <form class="upload" method="post" action="/upload.php" enctype="multipart/form-data">
            <?= Csrf::field() ?>

            <?php /* ------------------------------------------ 1. the file */ ?>
            <fieldset class="upload__step">
                <legend><span class="upload__num">١</span> ملف التصميم</legend>

                  <input type="hidden" name="MAX_FILE_SIZE" value="<?= UploadLimit::bytes() ?>">
                  <input type="file" name="artwork" id="artwork" required
                      accept="<?= e(implode(',', array_keys(Config::get('app.uploads.mime_types')))) ?>"
                       data-max-bytes="<?= UploadLimit::bytes() ?>">

                <p class="hint">
                    PNG أو JPG أو WebP أو SVG، حتى
                    <?= e(UploadLimit::mebibytes(UploadLimit::bytes())) ?> ميغابايت.
                    الأنسب ٣٣٠٠×٤١٣٠ بكسل عند ٣٠٠ نقطة/بوصة.
                    نفحص الدقّة على الخادم ونحذّرك تحت ١٥٠، ونرفض تحت ١٠٠.
                </p>
            </fieldset>

            <?php /* ------------------------------ 2. garment and quantities */ ?>
            <fieldset class="upload__step">
                <legend><span class="upload__num">٢</span> القطعة والكميات</legend>

                <div class="field">
                    <span class="field__label">القطعة</span>
                    <select name="product_id" required>
                        <?php foreach ($catalog['items'] as $product) { ?>
                            <option value="<?= (int) $product['id'] ?>"
                                <?= $selectedProduct === $product['id'] ? 'selected' : '' ?>>
                                <?= e($product['name']) ?> — <?= e(money($product['min_price'])) ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="field">
                    <span class="field__label">موضع الطباعة</span>
                    <div class="chips">
                        <?php foreach (['front' => 'الصدر', 'back' => 'الظهر', 'both' => 'الوجهان'] as $value => $text) { ?>
                            <label class="chip">
                                <input type="radio" name="placement" value="<?= e($value) ?>"
                                       <?= $value === 'front' ? 'checked' : '' ?>>
                                <span class="chip__text"><?= e($text) ?></span>
                            </label>
                        <?php } ?>
                    </div>
                </div>

                <?php /*
                    A grid, not a single quantity box. A team order is ten
                    shirts in five sizes; asking for one size at a time means
                    five trips through this form, or a phone call.
                */ ?>
                <div class="qty-grid__wrap">
                    <table class="qty-grid">
                        <caption>اكتب الكمية عند كل لون ومقاس. اتركها فارغة لما لا تريده.</caption>
                        <thead>
                            <tr>
                                <th scope="col">اللون</th>
                                <?php foreach ($sizes as $size) { ?>
                                    <th scope="col"><?= e($size['code']) ?></th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($colors as $color) { ?>
                                <tr>
                                    <th scope="row">
                                        <span class="qty-grid__dot" style="background:<?= e($color['hex']) ?>"></span>
                                        <?= e($color['name']) ?>
                                    </th>
                                    <?php foreach ($sizes as $size) { ?>
                                        <td>
                                            <?php /* Shown only once the header row is gone, below 620px. */ ?>
                                            <span class="qty-grid__size" aria-hidden="true"><?= e($size['code']) ?></span>
                                            <input type="number" min="0" max="200" inputmode="numeric"
                                                   name="qty[<?= (int) $color['id'] ?>:<?= (int) $size['id'] ?>]"
                                                   aria-label="<?= e($color['name'] . ' ' . $size['code']) ?>"
                                                   placeholder="0">
                                        </td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <p class="hint">
                    الخصم يُحسب في الخادم:
                    <?php $parts = [];
                    foreach ($tiers as $from => $percent) {
                        $parts[] = $from . ' قطعة → ' . $percent . '٪';
                    }
                    echo e(implode('، ', $parts)); ?>.
                </p>
            </fieldset>

            <?php /* ------------------------------------------ 3. the note */ ?>
            <fieldset class="upload__step">
                <legend><span class="upload__num">٣</span> ملاحظة <span class="upload__opt">اختيارية</span></legend>
                <textarea name="note" rows="3" maxlength="500"
                          placeholder="موعد التسليم، لون حبر معيّن، أي شيء نحتاج معرفته."></textarea>
            </fieldset>

            <button type="submit" class="button button--primary button--block">أضف إلى السلة</button>

            <p class="hint" style="margin-top:10px"><?= e(Config::get('shop.returns_notice')) ?></p>
        </form>
    </main>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
