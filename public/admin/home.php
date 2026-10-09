<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HomeSettingsService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\UploadLimit;

Auth::require();

/*
 * The landing page's own screen: the two category photographs, the banner over the
 * design editor, and which products are trending.
 *
 * WHY THE TRENDING PICKER IS ON THIS PAGE AND NOT ON THE PRODUCT FORM
 *
 * Because trending is a decision about the home page, not about a product. Ticking
 * a box on a product form tells you nothing about how many other products are
 * ticked or what order they will appear in -- and "eight at most, in this order" is
 * exactly the thing the owner is deciding. One screen showing all of them together
 * is the screen where that decision can actually be made.
 *
 * stored() rather than settings(): the form must show an empty field as empty. If
 * it read the page's own getter it would print the fallback text into the input,
 * and the next save would write the default into the database as though the owner
 * had typed it.
 */
$service  = HomeSettingsService::make();
$settings = $service->stored();

/*
 * The product list for the picker, read straight from the table.
 *
 * Not through ProductService: its listing is cached and shaped for a shop front --
 * active products only, with variants and images folded in. This screen needs the
 * inactive ones too, because a product deactivated while still marked trending is
 * precisely the state the owner needs to see and fix.
 */
$products = Db::pdo()->query(
    'SELECT p.id, p.name, p.is_active, p.is_trending, p.trending_order, p.image_path,
            g.name_ar AS garment_name
       FROM products p
  LEFT JOIN garments g ON g.id = p.garment_id
   ORDER BY p.is_trending DESC, p.trending_order ASC, p.name ASC'
)->fetchAll();

$trendingCount = count(array_filter(
    $products,
    static fn (array $p): bool => (int) $p['is_trending'] === 1 && (int) $p['is_active'] === 1
));

$notice = match (true) {
    isset($_GET['saved'])    => 'تم حفظ إعدادات الواجهة.',
    isset($_GET['trending']) => 'تم تحديث المنتجات الرائجة.',
    default                  => null,
};

/*
 * An upload that failed says so on the next page load, through the session rather
 * than the query string: the reason can be a sentence, and a sentence in a URL is
 * a sentence a visitor can rewrite and have echoed back at them.
 */
$error = $_SESSION['home_error'] ?? null;
unset($_SESSION['home_error']);

$title   = 'إعدادات الواجهة';
$current = 'home';

/** The three image fields, so the markup below is one loop rather than three. */
$images = [
    'printed_card_image' => ['بطاقة "الملابس المطبوعة"', 'تظهر في أول قسم تحت الهيرو. يُفضّل صورة رأسية بنسبة ٤:٥.'],
    'plain_card_image'   => ['بطاقة "الملابس السادة"', 'بنفس مقاس البطاقة الأولى حتى تتساوى البطاقتان.'],
    'promo_image'        => ['خلفية إعلان "صمّم بنفسك"', 'صورة عريضة. إن تركتها فارغة يُرسم تدرّج سكني أنيق بدلًا منها.'],
];

$body = static function () use ($settings, $images, $products, $trendingCount, $notice, $error): void {
    ?>
    <main class="admin__body">
        <div class="admin__head">
            <div>
                <h1>إعدادات الواجهة الرئيسية</h1>
                <p class="hint">
                    صور القسمين، وإعلان "صمّم بنفسك"، والمنتجات الرائجة.
                    كل ما تحفظه هنا يظهر في الصفحة الرئيسية فورًا.
                </p>
            </div>
            <a class="button" href="/" target="_blank" rel="noopener">معاينة الصفحة</a>
        </div>

        <?php if ($notice !== null) { ?>
            <p class="alert alert--ok"><?= e($notice) ?></p>
        <?php } ?>

        <?php if ($error !== null) { ?>
            <p class="alert alert--error"><?= e((string) $error) ?></p>
        <?php } ?>

        <?php /*
            enctype is on the form because it carries files. Without it the browser
            sends the file names and not the files, and the handler sees an empty
            $_FILES with no error in it -- which looks exactly like "no file was
            chosen" and is the single most confusing way an upload form can fail.
        */ ?>
        <form method="post" action="/admin/home-save.php" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="settings">

            <section class="panel">
                <h2>صور الأقسام والإعلان</h2>

                <div class="home-admin__grid">
                    <?php foreach ($images as $key => [$label, $hint]) { ?>
                        <div class="home-admin__field">
                            <h3 class="home-admin__label"><?= e($label) ?></h3>

                            <?php /*
                                The current image, shown before anything is chosen.
                                An upload form that does not show what is already
                                stored makes the owner open the site in another tab
                                to find out whether they are about to replace the
                                right picture.
                            */ ?>
                            <div class="home-admin__preview">
                                <?php if ($settings[$key] !== '') { ?>
                                    <img src="<?= e($settings[$key]) ?>" alt="<?= e($label) ?>"
                                         width="320" height="200" loading="lazy" decoding="async">
                                <?php } else { ?>
                                    <span class="home-admin__empty">لا صورة — سيُستخدم التدرّج</span>
                                <?php } ?>
                            </div>

                            <label class="field">
                                <span>استبدال الصورة</span>
                                <?php /*
                                    accept is a convenience for the file picker and
                                    nothing more. The handler checks the real type
                                    with finfo, because this attribute is a hint the
                                    browser may ignore and an attacker will.
                                */ ?>
                                <input type="file" name="<?= e($key) ?>"
                                       accept="image/jpeg,image/png,image/webp">
                            </label>

                            <?php if ($settings[$key] !== '') { ?>
                                <label class="field field--check">
                                    <input type="checkbox" name="clear[]" value="<?= e($key) ?>">
                                    <span>حذف الصورة الحالية</span>
                                </label>
                            <?php } ?>

                            <p class="hint"><?= e($hint) ?></p>
                        </div>
                    <?php } ?>
                </div>

                <p class="hint">
                    الأنواع المقبولة: JPEG و PNG و WebP. الحد الأقصى للحجم
                    <?= e(UploadLimit::mebibytes(UploadLimit::bytes())) ?> ميغابايت.
                    تُحفظ الصور في <code dir="ltr">uploads/home/</code> باسم عشوائي،
                    وتُحذف الصورة القديمة عند الاستبدال.
                </p>
            </section>

            <section class="panel">
                <h2>نصوص إعلان "صمّم بنفسك"</h2>

                <label class="field">
                    <span>العنوان</span>
                    <input type="text" name="promo_title" maxlength="120"
                           value="<?= e($settings['promo_title']) ?>"
                           placeholder="<?= e(App\Service\HomeSettingsService::DEFAULTS['promo_title']) ?>">
                </label>

                <label class="field">
                    <span>النص</span>
                    <textarea name="promo_text" rows="3" maxlength="400"
                              placeholder="<?= e(App\Service\HomeSettingsService::DEFAULTS['promo_text']) ?>"><?=
                        e($settings['promo_text']) ?></textarea>
                </label>

                <label class="field">
                    <span>نص الزر</span>
                    <input type="text" name="promo_button" maxlength="40"
                           value="<?= e($settings['promo_button']) ?>"
                           placeholder="<?= e(App\Service\HomeSettingsService::DEFAULTS['promo_button']) ?>">
                </label>

                <?php /*
                    The placeholders are the defaults, from the same constant the
                    page falls back to. So an owner who clears a field can see what
                    will be shown instead of it, rather than guessing.
                */ ?>
                <p class="hint">
                    اتركه فارغًا ليعود إلى النص الافتراضي الظاهر باللون الباهت.
                    زر الإعلان يفتح المحرر الحالي على <code dir="ltr">/design.php</code>.
                </p>
            </section>

            <button type="submit" class="button button--primary">حفظ الإعدادات</button>
        </form>

        <?php /*
            A second form, posted separately. The settings above and the trending
            list are two different decisions, and one submit button for both means
            a failed image upload takes the tick boxes down with it.
        */ ?>
        <form method="post" action="/admin/home-save.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="trending">

            <section class="panel">
                <h2>المنتجات الرائجة</h2>
                <p class="hint">
                    اختر ما يظهر في شريط "المنتجات الرائجة" على الصفحة الرئيسية، والترتيب
                    من الأصغر إلى الأكبر. تُعرض ثمانية كحد أقصى؛ المختار حاليًا:
                    <strong><?= (int) $trendingCount ?></strong>.
                </p>

                <?php if ($products === []) { ?>
                    <p class="hint">لا منتجات بعد. أضف منتجًا من <a href="/admin/products.php">شاشة المنتجات</a>.</p>
                <?php } else { ?>
                    <table class="home-admin__table">
                        <thead>
                            <tr>
                                <th scope="col">رائج</th>
                                <th scope="col">المنتج</th>
                                <th scope="col">النوع</th>
                                <th scope="col">الترتيب</th>
                                <th scope="col">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product) { ?>
                                <tr>
                                    <td>
                                        <?php /*
                                            The id travels in the checkbox's value,
                                            and the order in a field named by id.
                                            So an unticked row sends nothing at all
                                            and the handler does not have to work
                                            out which of two lists to trust.
                                        */ ?>
                                        <input type="checkbox" name="trending[]"
                                               value="<?= (int) $product['id'] ?>"
                                               aria-label="اجعل <?= e($product['name']) ?> رائجًا"
                                               <?= (int) $product['is_trending'] === 1 ? ' checked' : '' ?>>
                                    </td>
                                    <td><?= e($product['name']) ?></td>
                                    <td class="home-admin__muted"><?= e((string) ($product['garment_name'] ?? '—')) ?></td>
                                    <td>
                                        <input type="number" class="home-admin__order"
                                               name="order[<?= (int) $product['id'] ?>]"
                                               value="<?= (int) $product['trending_order'] ?>"
                                               min="0" max="999" step="1"
                                               aria-label="ترتيب <?= e($product['name']) ?>">
                                    </td>
                                    <td>
                                        <?php if ((int) $product['is_active'] === 1) { ?>
                                            <span class="badge badge--on">نشط</span>
                                        <?php } else { ?>
                                            <?php /*
                                                Said out loud. A product marked
                                                trending but deactivated will not
                                                appear on the home page, and the
                                                owner would otherwise be left
                                                wondering why their tick did nothing.
                                            */ ?>
                                            <span class="badge">معطّل — لن يظهر</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>

                    <button type="submit" class="button button--primary">حفظ المنتجات الرائجة</button>
                <?php } ?>
            </section>
        </form>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
