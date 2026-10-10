<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\DesignRepository;
use App\Repository\ProductRepository;
use App\Service\HeroSlideService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\HeroModels;
use App\Support\Lang;
use App\Support\Response;
use App\Support\Validator;

Auth::require();

/*
 * The slide editor (template D, the studio).
 *
 * Sections on one side, the hero itself in the middle, that section's fields
 * on the other, and one save bar for the lot. The preview is a frame, not a
 * panel: the hero's shape is decided by viewport media queries, and only a
 * frame has a viewport of its own to answer them (see hero-preview.php).
 *
 * A new slide (?new=1) opens empty. It used to open holding the first live
 * slide's words, so saving it without reading published a duplicate.
 */

$service = HeroSlideService::make();

$errors = $_SESSION['errors'] ?? [];
$old    = $_SESSION['old'] ?? null;
unset($_SESSION['errors'], $_SESSION['old']);

$id    = (int) ($_GET['id'] ?? 0);
$slide = $id > 0 ? $service->find($id) : null;

if ($id > 0 && $slide === null) {
    Response::redirect('/admin/');
}

$isNew = $slide === null;
$slide ??= $service->blank();

// A rejected submission comes back with the administrator's own values intact.
if ($old !== null) {
    $slide = array_merge($slide, $old);
    $slide['id']        = $id;
    $slide['stats']     = Validator::decodeList($old['stats'] ?? null);
    $slide['layout']    = HeroSlideService::decodeLayout($old['layout'] ?? null);
    $slide['is_active'] = !empty($old['is_active']);
}

$stats = array_pad($slide['stats'], HeroSlideService::STAT_COUNT, ['value' => '', 'label' => '']);

$models  = HeroModels::all();
$lookups = $service->editorLookups();
$colors  = ProductRepository::make()->colors();
$designs = DesignRepository::make()->all();

$name = trim((string) ($slide['wordmark'] ?? '')) !== ''
    ? (string) $slide['wordmark']
    : (trim((string) ($slide['tab_word'] ?? '')) !== '' ? (string) $slide['tab_word'] : Lang::get('admin.slides.untitled'));

$title     = $isNew ? Lang::get('admin.slide.new_title') : Lang::get('admin.slide.edit_title', ['name' => "\u{2068}" . $name . "\u{2069}"]);
$current   = 'slides';
/* The hero lives in the frame now, and so does three.js. */
$importmap = false;

$body = static function () use (
    $slide, $stats, $errors, $id, $isNew, $models, $colors, $designs, $lookups, $title
): void {
    $err = static fn (string $field): ?string => $errors[$field] ?? null;
    $live = ['data-live' => true];
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'back'     => '/admin/',
            'title'    => $title,
            'subtitle' => Lang::get($isNew ? 'admin.slide.new_subtitle' : 'admin.slide.edit_subtitle'),
            'actions'  => $isNew ? null : static function () use ($slide): void {
                admin_partial('status-chip', $slide['is_active']
                    ? ['label' => Lang::get('admin.slides.live'), 'tone' => 'active']
                    : ['label' => Lang::get('admin.slides.paused'), 'tone' => 'done']);
            },
        ]); ?>

        <?php admin_partial('form/error-summary', [
            'errors' => $errors,
            'ids'    => ['stats' => 'f-stat-0-value', 'layout' => 'slide-reset-layout',
                         'color_id' => 'f-color-id', 'design_id' => 'f-design-id',
                         'background_value' => 'f-background-value', 'poster_path' => 'f-poster-path',
                         'design_image_path' => 'f-design-image-path'],
        ]); ?>

        <form id="slide-form" method="post" action="/admin/save.php" data-token="<?= e(Csrf::token()) ?>" data-watch>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
            <?php /* Cast to an object: an empty PHP array encodes as [], and a JS
                     array silently drops the named properties the editor writes. */ ?>
            <input type="hidden" name="layout" id="layout-field" data-initial="<?= e(json_encode((object) $slide['layout'], JSON_UNESCAPED_UNICODE)) ?>"
                   value="<?= e(json_encode((object) $slide['layout'], JSON_UNESCAPED_UNICODE)) ?>">

            <?php admin_partial('studio', [
                'id'     => 'slide-studio',
                'label'  => Lang::get('admin.slide.studio'),
                'layersTitle' => Lang::get('admin.slide.sections'),
                'layers' => static function () use ($slide, $stats): void {
                    $filled = count(array_filter($stats, static fn (array $s): bool => trim((string) ($s['value'] ?? '')) !== ''));
                    $rows = [
                        'content' => ['file', trim((string) $slide['wordmark']) !== '' ? (string) $slide['wordmark'] : Lang::get('admin.slide.no_words')],
                        'cards'   => ['grid', $slide['show_badges'] ? Format::plural('admin.slides.cards_count', $filled) : Lang::get('admin.slides.cards_hidden')],
                        'scene'   => ['shirt', Lang::get($slide['has_model'] ? ($slide['design_id'] ? 'admin.slides.model_printed' : 'admin.slides.model_plain') : 'admin.slides.model_none')],
                        'settings' => ['gear', Lang::get($slide['is_active'] ? 'admin.slides.live' : 'admin.slides.paused')],
                    ];
                    $first = true;
                    foreach ($rows as $key => [$icon, $hint]) { ?>
                        <button type="button" class="layer-row<?= $first ? ' is-selected' : '' ?>" role="option" aria-selected="<?= $first ? 'true' : 'false' ?>" data-studio-item="<?= e($key) ?>">
                            <span class="layer-row__icon"><?= admin_icon($icon) ?></span>
                            <span class="layer-row__text">
                                <span><?= e(Lang::get('admin.slide.tab.' . $key)) ?></span>
                                <span class="layer-row__hint"><bdi><?= e($hint) ?></bdi></span>
                            </span>
                        </button>
                    <?php $first = false;
                    }
                },
                'layersFoot' => static function (): void { ?>
                    <p class="form-field__help"><?= e(Lang::get('admin.slide.drag_hint')) ?></p>
                    <button type="button" class="button button--secondary button--small" id="slide-reset-layout"><?= e(Lang::get('admin.slide.reset_layout')) ?></button>
                <?php },
                'tools' => static function (): void { ?>
                    <?php /*
                        Each width makes the frame that width. Not a zoom: the
                        document inside answers its own media queries, so 390
                        here folds the navigation and cuts one notch across the
                        top edge exactly as a phone does.
                    */ ?>
                    <div class="segmented segmented--small" role="group" aria-label="<?= e(Lang::get('admin.studio.device')) ?>">
                        <?php foreach ([1440 => 'desktop', 768 => 'tablet', 390 => 'phone'] as $width => $key) { ?>
                            <button type="button" class="segmented__option" data-preview-width="<?= $width ?>"
                                    aria-pressed="<?= $width === 1440 ? 'true' : 'false' ?>"><?= e(Lang::get('admin.slide.device.' . $key)) ?></button>
                        <?php } ?>
                    </div>

                    <?php /*
                        The preview's own language and direction, never the
                        panel's. The panel may be Arabic and right-to-left while
                        the site is English and left-to-right, and a preview that
                        inherited the panel showed the card mirrored against what
                        a visitor gets.
                    */ ?>
                    <div class="segmented segmented--small" role="group" aria-label="<?= e(Lang::get('admin.studio.preview_lang')) ?>">
                        <?php foreach (Lang::adminLocales() as $code) { ?>
                            <button type="button" class="segmented__option" data-preview-lang="<?= e($code) ?>" lang="<?= e($code) ?>"
                                    aria-pressed="<?= $code === 'en' ? 'true' : 'false' ?>"><?= e(Lang::nativeName($code)) ?></button>
                        <?php } ?>
                    </div>
                <?php },
                'canvas' => static function () use ($slide): void { ?>
                    <?php /*
                        A frame, so the hero has a viewport of its own to measure
                        and to answer media queries against. It is drawn scaled
                        down to fit the column; the width it reports to its own
                        scripts is the device width, not the column's.
                    */ ?>
                    <div class="preview-stage" id="preview-stage">
                        <iframe class="preview-frame" id="hero-preview" title="<?= e(Lang::get('admin.slide.preview')) ?>"
                                data-lang="en"
                                src="/admin/hero-preview.php?id=<?= (int) $slide['id'] ?>&amp;lang=en"></iframe>
                    </div>
                <?php },
                'panels' => [
                    'content' => ['label' => Lang::get('admin.slide.tab.content'), 'body' => static function () use ($slide, $err, $live): void {
                        /* dir="auto" on every free-text field: a Latin phrase
                           typed into a right-to-left input read back to front
                           ("gsm 240"), which is what the storefront would print. */
                        admin_partial('form/field', [
                            'type' => 'text', 'name' => 'tab_word', 'label' => Lang::get('admin.slide.f.tab_word'),
                            'value' => (string) $slide['tab_word'], 'maxlength' => 40, 'dir' => 'auto',
                            'help' => Lang::get('admin.slide.tab_word_help'), 'error' => $err('tab_word'), 'attrs' => $live,
                        ]);
                        admin_partial('form/field', [
                            'type' => 'text', 'name' => 'wordmark', 'label' => Lang::get('admin.slide.f.wordmark'),
                            'value' => (string) $slide['wordmark'], 'maxlength' => 40, 'dir' => 'auto',
                            'help' => Lang::get('admin.slide.wordmark_help'), 'error' => $err('wordmark'), 'attrs' => $live,
                        ]);
                        admin_partial('form/field', [
                            'type' => 'textarea', 'name' => 'tagline', 'label' => Lang::get('admin.slide.f.tagline'),
                            'value' => (string) $slide['tagline'], 'maxlength' => 200, 'dir' => 'auto',
                            'error' => $err('tagline'), 'attrs' => $live + ['rows' => 3],
                        ]);
                        admin_partial('form/field', [
                            'type' => 'text', 'name' => 'cta_label', 'label' => Lang::get('admin.slide.f.cta_label'),
                            'value' => (string) $slide['cta_label'], 'maxlength' => 120, 'dir' => 'auto',
                            'error' => $err('cta_label'), 'attrs' => $live,
                        ]);
                        admin_partial('form/field', [
                            'type' => 'text', 'name' => 'cta_url', 'label' => Lang::get('admin.slide.f.cta_url'),
                            'value' => (string) $slide['cta_url'], 'dir' => 'ltr', 'help' => Lang::get('admin.slide.cta_url_help'),
                            'error' => $err('cta_url'), 'attrs' => $live + ['placeholder' => '/catalog.php', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'translate' => 'no'],
                        ]);
                    }],

                    'cards' => ['label' => Lang::get('admin.slide.tab.cards'), 'body' => static function () use ($slide, $stats, $err, $live): void {
                        admin_partial('form/field', [
                            'type' => 'toggle', 'name' => 'show_badges', 'label' => Lang::get('admin.slide.f.show_badges'),
                            'value' => (bool) $slide['show_badges'], 'attrs' => $live,
                        ]);
                        foreach ($stats as $i => $stat) { ?>
                            <fieldset class="form-field__group">
                                <legend class="form-field__label"><?= e(Lang::get('admin.slide.card_n', ['n' => Format::number($i + 1)])) ?></legend>
                                <div class="form-grid">
                                    <?php admin_partial('form/field', [
                                        'type' => 'text', 'name' => 'stats[' . $i . '][value]', 'id' => 'f-stat-' . $i . '-value',
                                        'label' => Lang::get('admin.slide.f.stat_value'), 'value' => (string) ($stat['value'] ?? ''),
                                        'maxlength' => 20, 'dir' => 'auto', 'required' => true, 'attrs' => $live,
                                        'invalid' => Lang::get('admin.form.required'),
                                    ]); ?>
                                    <?php admin_partial('form/field', [
                                        'type' => 'text', 'name' => 'stats[' . $i . '][label]', 'id' => 'f-stat-' . $i . '-label',
                                        'label' => Lang::get('admin.slide.f.stat_label'), 'value' => (string) ($stat['label'] ?? ''),
                                        'maxlength' => 40, 'dir' => 'auto', 'required' => true, 'attrs' => $live,
                                        'invalid' => Lang::get('admin.form.required'),
                                    ]); ?>
                                </div>
                            </fieldset>
                        <?php }
                        if ($err('stats') !== null) { ?>
                            <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e((string) $err('stats')) ?></span></p>
                        <?php }
                    }],

                    'scene' => ['label' => Lang::get('admin.slide.tab.scene'), 'body' => static function () use ($slide, $models, $colors, $designs, $err, $live): void {
                        admin_partial('form/field', [
                            'type' => 'toggle', 'name' => 'has_model', 'id' => 'has-model', 'label' => Lang::get('admin.slide.f.has_model'),
                            'value' => (bool) $slide['has_model'], 'attrs' => $live,
                        ]); ?>
                        <?php /*
                            Hidden as a group the moment the switch is off.
                            Nothing here is posted then, and HeroSlideService asks
                            nothing of a slide that shows no garment -- so a stale
                            choice left in a select cannot fail a save.
                        */ ?>
                        <div class="form-stack" data-model-fields<?= $slide['has_model'] ? '' : ' hidden' ?>>
                            <?php admin_partial('form/field', [
                                'type' => 'select', 'name' => 'model_key', 'label' => Lang::get('admin.slide.f.model_key'),
                                'value' => (string) $slide['model_key'], 'error' => $err('model_key'), 'attrs' => $live,
                                'options' => array_map(static fn (string $key, array $m): array => ['value' => $key, 'label' => (string) $m['label']], array_keys($models), $models),
                            ]); ?>
                            <?php admin_partial('form/field', [
                                'type' => 'swatches', 'name' => 'color_id', 'id' => 'f-color-id', 'label' => Lang::get('admin.slide.f.color_id'),
                                'value' => (string) (int) $slide['color_id'], 'error' => $err('color_id'), 'attrs' => $live,
                                'options' => array_map(static fn (array $c): array => ['value' => (int) $c['id'], 'label' => (string) $c['name'], 'hex' => (string) $c['hex']], $colors),
                            ]); ?>
                            <?php admin_partial('form/field', [
                                'type' => 'select', 'name' => 'design_id', 'id' => 'f-design-id', 'label' => Lang::get('admin.slide.f.design_id'),
                                'value' => (string) (int) $slide['design_id'], 'error' => $err('design_id'), 'attrs' => $live,
                                'help' => Lang::get('admin.slide.design_help'),
                                'options' => array_merge(
                                    [['value' => '', 'label' => Lang::get('admin.slide.design_none')]],
                                    array_map(static fn (array $d): array => ['value' => (int) $d['id'], 'label' => (string) $d['name']], $designs)
                                ),
                            ]); ?>
                            <?php /* Only a printed shirt has a place to print it. */ ?>
                            <div data-placement-field<?= $slide['design_id'] ? '' : ' hidden' ?>>
                                <?php admin_partial('form/field', [
                                    'type' => 'segmented', 'name' => 'placement', 'label' => Lang::get('admin.slide.f.placement'),
                                    'value' => (string) $slide['placement'], 'error' => $err('placement'), 'attrs' => $live,
                                    'options' => array_map(static fn (string $v): array => ['value' => $v, 'label' => Lang::get('admin.side.' . $v)], HeroSlideService::PLACEMENTS),
                                ]); ?>
                            </div>
                            <?php /*
                                The old one-off upload. A slide that names a
                                catalogue design above ignores this; it is here so
                                a slide saved before the designs table existed
                                keeps printing what it printed.
                            */ ?>
                            <?php admin_partial('form/image-picker', [
                                'name' => 'design_image_path', 'id' => 'f-design-image-path', 'label' => Lang::get('admin.slide.f.design_image_path'),
                                'value' => (string) $slide['design_image_path'], 'ratio' => '1 / 1',
                                'help' => Lang::get('admin.slide.design_image_help'), 'error' => $err('design_image_path'), 'attrs' => $live,
                            ]); ?>
                        </div>
                    <?php }],

                    'settings' => ['label' => Lang::get('admin.slide.tab.settings'), 'body' => static function () use ($slide, $err, $live): void { ?>
                        <div class="form-field" data-field>
                            <label class="form-field__label" for="f-background-value"><?= e(Lang::get('admin.slide.f.background_value')) ?></label>
                            <div class="colour-field">
                                <input class="input" type="text" id="f-background-value" name="background_value" dir="ltr" spellcheck="false"
                                       translate="no" autocomplete="off" value="<?= e((string) $slide['background_value']) ?>" data-live
                                       aria-describedby="f-background-value-help"<?= $err('background_value') !== null ? ' aria-invalid="true"' : '' ?>>
                                <input class="colour-field__swatch" type="color" id="background-color" aria-label="<?= e(Lang::get('admin.slide.pick_colour')) ?>"
                                       value="<?= e(str_starts_with((string) $slide['background_value'], '#') ? (string) $slide['background_value'] : '#141414') ?>">
                                <button type="button" class="button button--secondary button--small" data-upload="background_value"><?= e(Lang::get('admin.form.drop_choose')) ?></button>
                            </div>
                            <p class="form-field__help" id="f-background-value-help"><?= e(Lang::get('admin.slide.background_help')) ?></p>
                            <?php if ($err('background_value') !== null) { ?>
                                <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e((string) $err('background_value')) ?></span></p>
                            <?php } ?>
                        </div>

                        <?php admin_partial('form/image-picker', [
                            'name' => 'poster_path', 'id' => 'f-poster-path', 'label' => Lang::get('admin.slide.f.poster_path'),
                            'value' => (string) $slide['poster_path'], 'ratio' => '16 / 9',
                            'help' => Lang::get('admin.slide.poster_help'), 'error' => $err('poster_path'), 'attrs' => $live,
                        ]); ?>

                        <?php admin_partial('form/field', [
                            'type' => 'toggle', 'name' => 'parallax_enabled', 'label' => Lang::get('admin.slide.f.parallax'),
                            'value' => (bool) $slide['parallax_enabled'], 'help' => Lang::get('admin.slide.parallax_help'), 'attrs' => $live,
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'toggle', 'name' => 'is_active', 'label' => Lang::get('admin.slide.f.is_active'),
                            'value' => (bool) $slide['is_active'], 'help' => Lang::get('admin.slide.active_help'),
                        ]); ?>
                        <?php admin_partial('form/field', [
                            'type' => 'number', 'name' => 'sort_order', 'label' => Lang::get('admin.slide.f.sort_order'),
                            'value' => (string) (int) $slide['sort_order'], 'help' => Lang::get('admin.slide.sort_help'),
                            'attrs' => ['min' => 0, 'max' => 999, 'step' => 1, 'inputmode' => 'numeric'], 'error' => $err('sort_order'),
                        ]); ?>
                    <?php }],
                ],
            ]); ?>
        </form>

        <?php admin_partial('form/save-bar', [
            'form' => 'slide-form',
            'save' => Lang::get($isNew ? 'admin.slide.create' : 'admin.form.save'),
        ]); ?>
    </main>

    <input type="file" id="upload-input" accept="image/jpeg,image/png,image/webp" hidden>

    <?php /* Colour, design and placement resolve in the browser against these,
             so a dropdown moves the shirt without a round trip. */ ?>
    <script type="application/json" id="hero-editor-lookups"><?= json_island($lookups) ?></script>

    <script type="module" src="/assets/js/admin-hero.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
