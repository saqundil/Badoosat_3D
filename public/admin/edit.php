<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\DesignRepository;
use App\Repository\ProductRepository;
use App\Service\HeroSlideService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\HeroModels;
use App\Support\Response;

Auth::require();

$service = HeroSlideService::make();

$errors = $_SESSION['errors'] ?? [];
$old    = $_SESSION['old'] ?? null;
unset($_SESSION['errors'], $_SESSION['old']);

$id    = (int) ($_GET['id'] ?? 0);
$slide = $id > 0 ? $service->find($id) : null;

if ($id > 0 && $slide === null) {
    Response::redirect('/admin/');
}

$slide ??= $service->blank();

// A rejected submission comes back with the administrator's own values intact.
if ($old !== null) {
    $slide = array_merge($slide, $old);
    $slide['id']        = $id;
    $slide['stats']     = App\Support\Validator::decodeList($old['stats'] ?? null);
    $slide['layout']    = HeroSlideService::decodeLayout($old['layout'] ?? null);
    $slide['is_active'] = !empty($old['is_active']);
}

$stats   = array_pad($slide['stats'], HeroSlideService::STAT_COUNT, ['value' => '', 'label' => '']);

/* The same resolution the homepage makes, so the preview is fitted to the
   geometry the visitor's hero will use rather than to a key spelled out here. */
$garment = HeroSlideService::withGarment([$slide])['garment'];

$models  = HeroModels::all();
$lookups = $service->editorLookups();
$colors  = ProductRepository::make()->colors();
$designs = DesignRepository::make()->all();

/* The cart's own words for the cart's own values; see public/cart.php. */
$placementLabel = static fn (string $value): string => match ($value) {
    'back'  => 'الظهر',
    'both'  => 'الوجهان',
    default => 'الصدر',
};

$title     = $id > 0 ? 'تحرير شريحة' : 'شريحة جديدة';
$current   = 'slides';
/* The hero lives in the frame now, and so does three.js. */
$importmap = false;

$body = static function () use (
    $slide, $stats, $garment, $errors, $id, $models, $colors, $designs, $placementLabel, $lookups
): void {
    ?>
    <?php if ($errors !== []) { ?>
        <div class="admin__body">
            <div class="alert alert--error">
                <p>تعذّر الحفظ:</p>
                <ul><?php foreach ($errors as $message) { ?><li><?= e($message) ?></li><?php } ?></ul>
            </div>
        </div>
    <?php } ?>

    <div class="editor">
        <section class="editor__preview hero-editor">
            <div class="editor__preview-bar">
                <p class="hint hint--tight">
                    اسحب الكلمة أو السطر أو بطاقات الإحصاء داخل المعاينة.
                    المعاينة هي مكوّن الهيرو نفسه بمقاس الجهاز الحقيقي، لا نسخة مصغّرة منه.
                </p>
                <button type="button" class="button" id="reset-layout">إعادة المواضع</button>
            </div>

            <div class="editor__preview-bar">
                <?php /*
                    Each of these makes the frame that width. Not a zoom: the
                    document inside answers its own media queries, so 390 here
                    folds the navigation and cuts one notch across the top edge
                    exactly as a phone does.
                */ ?>
                <div class="switch" role="group" aria-label="مقاس المعاينة">
                    <?php foreach ([1440 => 'سطح مكتب', 768 => 'لوحي', 390 => 'هاتف'] as $width => $name) { ?>
                        <button type="button" class="switch__button" data-preview-width="<?= $width ?>"
                                aria-pressed="<?= $width === 1440 ? 'true' : 'false' ?>">
                            <?= e($name) ?> <span class="switch__note"><?= $width ?></span>
                        </button>
                    <?php } ?>
                </div>

                <?php /*
                    The preview's own language and direction, never the panel's.
                    The panel is Arabic and right-to-left; the site is English
                    and left-to-right, and a preview that inherited the panel
                    showed the card mirrored against what a visitor gets.
                */ ?>
                <div class="switch" role="group" aria-label="لغة المعاينة">
                    <?php foreach (['en' => 'إنجليزي', 'ar' => 'عربي'] as $code => $name) { ?>
                        <button type="button" class="switch__button" data-preview-lang="<?= $code ?>"
                                aria-pressed="<?= $code === 'en' ? 'true' : 'false' ?>"><?= e($name) ?></button>
                    <?php } ?>
                </div>
            </div>

            <?php /*
                A frame, so the hero has a viewport of its own to measure and to
                answer media queries against. It is drawn scaled down to fit the
                column; the width it reports to its own scripts is the device
                width, not the column's.
            */ ?>
            <div class="preview-stage" id="preview-stage">
                <iframe class="preview-frame" id="hero-preview" title="معاينة الشريحة"
                        data-lang="en"
                        src="/admin/hero-preview.php?id=<?= (int) $slide['id'] ?>&amp;lang=en"></iframe>
            </div>
        </section>

        <form class="editor__form panel" id="slide-form" method="post" action="/admin/save.php"
              data-token="<?= e(Csrf::token()) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
            <?php /* Cast to an object: an empty PHP array encodes as [], and a JS
                     array silently drops the named properties the editor writes. */ ?>
            <input type="hidden" name="layout" id="layout-field"
                   value="<?= e(json_encode((object) $slide['layout'], JSON_UNESCAPED_UNICODE)) ?>">

            <div class="editor__form-head">
                <h1><?= $id > 0 ? 'تحرير شريحة' : 'شريحة جديدة' ?></h1>
                <div class="admin__bar-group">
                    <a class="button" href="/admin/">رجوع</a>
                    <button type="submit" class="button button--primary">حفظ</button>
                </div>
            </div>

            <fieldset>
                <legend>النصوص</legend>

                <label class="field">
                    <span>كلمة اللسان</span>
                    <input type="text" name="tab_word" maxlength="40" data-live
                           value="<?= e($slide['tab_word']) ?>">
                </label>

                <label class="field">
                    <span>الكلمة العملاقة</span>
                    <input type="text" name="wordmark" maxlength="40" data-live
                           value="<?= e($slide['wordmark']) ?>">
                </label>

                <label class="field">
                    <span>السطر التسويقي</span>
                    <textarea name="tagline" maxlength="200" rows="3" data-live><?= e($slide['tagline']) ?></textarea>
                </label>
            </fieldset>

            <fieldset>
                <legend>بطاقتا الإحصاء</legend>
                <?php foreach ($stats as $i => $stat) { ?>
                    <div class="stat-row">
                        <label class="field">
                            <span>القيمة <?= $i + 1 ?></span>
                            <input type="text" name="stats[<?= $i ?>][value]" maxlength="20" data-live
                                   value="<?= e($stat['value'] ?? '') ?>">
                        </label>
                        <label class="field">
                            <span>العنوان <?= $i + 1 ?></span>
                            <input type="text" name="stats[<?= $i ?>][label]" maxlength="40" data-live
                                   value="<?= e($stat['label'] ?? '') ?>">
                        </label>
                    </div>
                <?php } ?>
            </fieldset>

            <fieldset>
                <legend>المجسّم</legend>

                <label class="field field--check">
                    <input type="checkbox" name="has_model" value="1" id="has-model" data-live
                           <?= $slide['has_model'] ? ' checked' : '' ?>>
                    <span>تعرض هذه الشريحة مجسّمًا</span>
                </label>

                <?php /*
                    Hidden as a group the moment the switch is off. Nothing here
                    is posted then, and HeroSlideService asks nothing of a slide
                    that shows no garment -- so a stale choice left in a select
                    cannot fail a save.
                */ ?>
                <div class="model-fields" data-model-fields<?= $slide['has_model'] ? '' : ' hidden' ?>>
                    <label class="field">
                        <span>المجسّم</span>
                        <select name="model_key" data-live>
                            <?php foreach ($models as $key => $model) { ?>
                                <option value="<?= e((string) $key) ?>"<?=
                                    (string) $key === (string) $slide['model_key'] ? ' selected' : '' ?>><?=
                                    e((string) $model['label']) ?></option>
                            <?php } ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>لون القميص</span>
                        <select name="color_id" data-live>
                            <?php foreach ($colors as $color) { ?>
                                <option value="<?= (int) $color['id'] ?>"<?=
                                    (int) $color['id'] === (int) $slide['color_id'] ? ' selected' : '' ?>><?=
                                    e($color['name'] . ' — ' . $color['hex']) ?></option>
                            <?php } ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>التصميم المطبوع</span>
                        <select name="design_id" data-live id="design-id">
                            <option value="">بلا تصميم — قميص سادة</option>
                            <?php foreach ($designs as $design) { ?>
                                <option value="<?= (int) $design['id'] ?>"<?=
                                    (int) $design['id'] === (int) $slide['design_id'] ? ' selected' : '' ?>><?=
                                    e($design['name']) ?></option>
                            <?php } ?>
                        </select>
                    </label>

                    <?php /* Only a printed shirt has a place to print it. */ ?>
                    <label class="field" data-placement-field<?= $slide['design_id'] ? '' : ' hidden' ?>>
                        <span>موضع التصميم</span>
                        <select name="placement" data-live>
                            <?php foreach (HeroSlideService::PLACEMENTS as $value) { ?>
                                <option value="<?= e($value) ?>"<?=
                                    $value === $slide['placement'] ? ' selected' : '' ?>><?=
                                    e($placementLabel($value)) ?></option>
                            <?php } ?>
                        </select>
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend>الخلفية والبطاقات</legend>

                <label class="field field--check">
                    <input type="checkbox" name="show_badges" value="1" data-live
                           <?= $slide['show_badges'] ? ' checked' : '' ?>>
                    <span>إظهار بطاقتَي الإحصاء</span>
                </label>

                <label class="field field--check">
                    <input type="checkbox" name="parallax_enabled" value="1" data-live
                           <?= $slide['parallax_enabled'] ? ' checked' : '' ?>>
                    <span>تحريك الطبقات مع حركة المؤشّر</span>
                </label>
                <p class="hint hint--tight">
                    الحركة معطّلة دائمًا لمن فعّل «تقليل الحركة» في نظامه، مهما كانت قيمة هذا الخيار.
                    داخل المعاينة تتوقّف الحركة أثناء سحب العناصر.
                </p>

                <label class="field">
                    <span>خلفية البطاقة (لون أو مسار صورة)</span>
                    <span class="field__row">
                        <input type="text" name="background_value" id="background-value" data-live
                               value="<?= e($slide['background_value']) ?>">
                        <input type="color" id="background-color" aria-label="لون الخلفية"
                               value="<?= e(str_starts_with($slide['background_value'], '#') ? $slide['background_value'] : '#141414') ?>">
                        <button type="button" class="button" data-upload="background_value">رفع</button>
                    </span>
                </label>

                <?php /*
                    The old one-off upload. A slide that names a catalogue design
                    above ignores this; it is here so a slide saved before the
                    designs table existed keeps printing what it printed.
                */ ?>
                <label class="field">
                    <span>صورة تصميم مرفوعة (بديل قديم)</span>
                    <span class="field__row">
                        <input type="text" name="design_image_path" data-live
                               value="<?= e($slide['design_image_path']) ?>"
                               placeholder="اتركه فارغًا — يفضَّل اختيار تصميم من القائمة">
                        <button type="button" class="button" data-upload="design_image_path">رفع</button>
                    </span>
                </label>

                <label class="field">
                    <span>اللقطة الثابتة</span>
                    <span class="field__row">
                        <input type="text" name="poster_path" data-live value="<?= e($slide['poster_path']) ?>">
                        <button type="button" class="button" data-upload="poster_path">رفع</button>
                    </span>
                </label>
            </fieldset>

            <fieldset>
                <legend>الزر والترتيب</legend>

                <label class="field">
                    <span>نص الزر</span>
                    <input type="text" name="cta_label" maxlength="120" data-live value="<?= e($slide['cta_label']) ?>">
                </label>

                <label class="field">
                    <span>رابط الزر</span>
                    <input type="text" name="cta_url" data-live value="<?= e($slide['cta_url']) ?>" placeholder="/catalog.php">
                </label>

                <label class="field">
                    <span>الترتيب</span>
                    <input type="number" name="sort_order" min="0" value="<?= (int) $slide['sort_order'] ?>">
                </label>

                <label class="field field--check">
                    <input type="checkbox" name="is_active" value="1"<?= $slide['is_active'] ? ' checked' : '' ?>>
                    <span>شريحة نشطة</span>
                </label>
            </fieldset>
        </form>
    </div>

    <input type="file" id="upload-input" accept="image/jpeg,image/png,image/webp" hidden>

    <?php /* Colour, design and placement resolve in the browser against these,
             so a dropdown moves the shirt without a round trip. */ ?>
    <script type="application/json" id="hero-editor-lookups"><?= json_island($lookups) ?></script>

    <script type="module" src="/assets/js/admin-hero.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
