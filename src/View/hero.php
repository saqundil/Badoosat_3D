<?php

declare(strict_types=1);

/**
 * The hero component. The homepage and the admin editor include this same file,
 * so what an administrator drags is the thing visitors get.
 *
 * @var array  $slides  hero slide rows, already hydrated
 * @var array  $garment print geometry from config/garments.php
 * @var bool   $auto    bind on load (homepage) or leave to the caller (editor)
 * @var string $label   the section's accessible name
 * @var ?string $heroNote  why there is nothing to show, when there is nothing
 * @var bool   $chrome  carry the site navigation in the card's notches
 * @var int    $cartCount
 */

use App\Support\Lang;
use App\Support\Shop;

$auto     = $auto ?? true;
$label    = $label ?? 'Featured products';
$heroNote = $heroNote ?? null;
$first    = $slides[0] ?? null;

/*
 * The home page has no navigation bar above the hero: the card runs to the top
 * of the page and the chrome floats in three notches cut out of it. The admin
 * editor includes this same file to preview one slide inside a panel, where
 * there is no site navigation to carry and the light tab is still what an
 * administrator drags -- so it renders the card as it was.
 */
$chrome    = $chrome ?? $auto;
$cartCount = $cartCount ?? 0;

/*
 * Whether this hero has a stage at all.
 *
 * A slide can say it shows no garment, and then there is nothing to draw: no
 * canvas, no poster, and -- because hero.js only reaches for three.js once a
 * slide with a model is on screen -- not one byte of the library fetched.
 *
 * The stage is one element shared by every slide, so the question here is
 * whether ANY slide wants one. A deck where none does emits no stage element,
 * and the 3D half of the controller is never entered.
 */
$hasStage = false;

foreach ($slides as $slideRow) {
    if (!empty($slideRow['has_model'])) {
        $hasStage = true;

        break;
    }
}

/*
 * The editor is the exception. An administrator turning the switch on has to
 * see a shirt appear without saving and reloading, so the preview always has a
 * canvas to build into -- hidden, and never built while the draft says there is
 * no model, so it costs the same nothing until it is asked for. $auto is false
 * only in the editor; the visitor's hero is the branch above.
 */
$hasStage = $hasStage || !$auto;

/* The first slide decides what is painted before any script runs. */
$firstHasModel = !empty($first['has_model']);

/*
 * Short on screen, full to a screen reader. The notch is cut to the row's
 * measured width, so every character in a link is a millimetre of the card
 * taken out -- and "Plain" says as much on a navigation bar as "Plain
 * products" does, which the accessible name still says in full.
 */
$navLinks = [
    ['plain', '/catalog.php?type=plain'],
    ['printed', '/catalog.php?type=printed'],
    ['about', '#about'],
];

$background = (string) ($first['background_value'] ?? '#141414');
$isColour   = str_starts_with($background, '#');
$stats      = $first['stats'] ?? [];
$layout     = $first['layout'] ?? [];

/** Nudge for one element, as inline custom properties. Absent means untouched. */
$nudge = static function (string $element) use ($layout): string {
    $offset = $layout[$element] ?? null;

    if ($offset === null) {
        return '';
    }

    return sprintf(' style="--dx:%s;--dy:%s"', (float) $offset['dx'], (float) $offset['dy']);
};
?>
<section class="hero<?= $chrome ? ' hero--chrome' : '' ?>" data-hero<?= $auto ? ' data-hero-auto' : '' ?> aria-label="<?= e($label) ?>">
    <script type="application/json" data-hero-data><?= json_island([
        'slides'  => $slides,
        'garment' => $garment,
    ]) ?></script>

    <div class="hero__card<?= $firstHasModel ? '' : ' hero__card--no-model' ?>" data-hero-card
         <?= $hasStage ? '' : 'data-hero-no-stage' ?>>
        <?php /*
            Only the background and the wordmark live inside .hero__surface. Its
            clip-path cuts the notches out of everything it contains, and the
            clip makes it a stacking context of its own, so anything that has to
            appear ABOVE the cut — the garment first of all, and every floating
            group in a notch — has to be a sibling of it rather than a child.
        */ ?>
        <div class="hero__surface"<?= $chrome ? ' data-hero-clip' : '' ?>>
            <div class="hero__bg" data-hero-bg
                 style="<?= $isColour
                    ? 'background-color:' . e($background)
                    : 'background-image:url(' . e('"' . $background . '"') . ')' ?>"></div>

            <div class="hero__layer hero__layer--back" data-hero-layer="back" aria-hidden="true">
                <div class="hero__wordmark" data-hero-wordmark<?= $nudge('wordmark') ?><?= empty($first['wordmark']) ? ' hidden' : '' ?>><?=
                    e($first['wordmark'] ?? '') ?></div>
            </div>
        </div>

        <?php if (!$chrome) { ?>
            <div class="hero__tab" data-hero-tab aria-hidden="true"<?= $nudge('tab') ?><?= empty($first['tab_word']) ? ' hidden' : '' ?>>
                <span class="hero__tab-word" data-hero-tab-word><?= e($first['tab_word'] ?? '') ?></span>
            </div>
        <?php } ?>

        <?php if ($hasStage) { ?>
            <?php /*
                The stage carries the clip too. The notches are holes in the card,
                and a garment drawn across one would cover the links floating in it.

                Emitted only where some slide has a model. Where none does there
                is no canvas for three.js to draw into and no poster to cross-fade
                from, so the element itself is the thing left out -- not hidden,
                left out, because a hidden canvas is still a canvas the controller
                would find and build a WebGL context for.
            */ ?>
            <div class="hero__stage" data-hero-stage<?= $chrome ? ' data-hero-clip' : '' ?>
                 <?= $firstHasModel ? '' : 'hidden' ?>>
                <?php if (!empty($first['poster_path']) && $firstHasModel) { ?>
                    <?php /* No decoding="async": this is the LCP element, and deferring
                             its decode is exactly what delays the paint being measured. */ ?>
                    <img class="hero__poster" data-hero-poster
                         src="<?= e($first['poster_path']) ?>"
                         width="1600" height="1000"
                         fetchpriority="high" alt="">
                <?php } ?>
                <canvas class="hero__canvas" data-hero-canvas aria-hidden="true"></canvas>
            </div>
        <?php } ?>

        <div class="hero__layer hero__layer--front" data-hero-layer="front" aria-hidden="true">
            <p class="hero__tagline" data-hero-tagline<?= $nudge('tagline') ?><?= empty($first['tagline']) ? ' hidden' : '' ?>><?=
                e($first['tagline'] ?? '') ?></p>

            <?php /* show_badges is the administrator's switch; an empty stats
                     list is the same answer arrived at from the other side. */ ?>
            <div class="hero__stats" data-hero-stats<?= $nudge('stats') ?><?=
                $stats === [] || empty($first['show_badges']) ? ' hidden' : '' ?>>
                <?php foreach ($stats as $stat) { ?>
                    <div class="hero__stat">
                        <span class="hero__stat-value"><?= e($stat['value'] ?? '') ?></span>
                        <span class="hero__stat-label"><?= e($stat['label'] ?? '') ?></span>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php if ($first === null) { ?>
            <?php /* An empty dark rectangle gives whoever is setting this up nothing
                     to go on. Say what is missing instead. */ ?>
            <div class="hero__empty">
                <strong><?= e(Lang::get('hero.empty.title')) ?></strong>
                <p>
                    <?= $heroNote !== null
                        ? e($heroNote)
                        : e(Lang::get('hero.empty.text')) ?>
                </p>
                <p><code>mysql -u root tshirt_store &lt; schema.sql</code></p>
            </div>
        <?php } ?>

        <div class="hero__ui">
            <?php if (!$chrome) { ?>
                <a class="hero__cta" data-hero-cta
                   href="<?= e($first['cta_url'] ?? '#') ?>"<?= empty($first['cta_label']) ? ' hidden' : '' ?>><?=
                    e($first['cta_label'] ?? '') ?></a>
            <?php } ?>
            <div class="hero__dots" data-hero-dots role="group" aria-label="<?= e(Lang::get('hero.slides')) ?>"></div>
        </div>

        <?php if ($chrome) { ?>
            <?php /*
                The three floating groups. Every one of them is a sibling of the
                clipped layers above, never a child: a notch is a piece removed
                from those layers, so anything placed inside one would be removed
                with it.

                Each group is flush into its own corner of the card. The notch
                cut behind it is the group's measured box plus one gap on the two
                inner sides -- measured, because "مطبوع" and "عن الورشة" are not
                the same width, and neither are they the same width as the English
                that goes in their place under lang/en.php. A notch sized for one
                would crop the other.
            */ ?>
            <?php /*
                The wrapper is nothing at all on a wide card -- display:contents,
                so the two groups position themselves into their own corners. On
                a narrow one it becomes the row itself, spanning the whole top
                edge with both groups inside it, and then the wrapper is what the
                single notch is cut to.
            */ ?>
            <div class="hero__chrome" data-hero-chrome>
            <div class="hero__notch hero__notch--start" data-hero-notch="start">
                <?php
                $brandClass  = 'brand brand--hero';
                $brandHeight = App\Support\Brand::HERO_HEIGHT;
                require __DIR__ . '/brand-mark.php';
                ?>

                <button class="hero__menu-button" type="button"
                        data-hero-menu-button aria-expanded="false" aria-controls="hero-nav">
                    <span class="hero__menu-bars" aria-hidden="true"></span>
                    <span class="visually-hidden"><?= e(Lang::get('nav.menu')) ?></span>
                </button>

                <nav class="hero__nav" id="hero-nav" data-hero-menu aria-label="<?= e(Lang::get('nav.main')) ?>">
                    <?php foreach ($navLinks as [$key, $href]) { ?>
                        <?php $full = Lang::get('nav.' . $key . '.full'); ?>
                        <a href="<?= e($href) ?>" aria-label="<?= e($full) ?>" title="<?= e($full) ?>"><?=
                            e(Lang::get('nav.' . $key . '.short')) ?></a>
                    <?php } ?>
                </nav>
            </div>

            <div class="hero__notch hero__notch--end" data-hero-notch="end">
                <a class="hero__cart" href="/cart.php">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false">
                        <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                              stroke-linejoin="round" d="M3 4h2.2l2 11.2h9.9l2-8.2H6.4M9 20.2h.01M17 20.2h.01"/>
                    </svg>
                    <span class="visually-hidden"><?= e(Lang::get('nav.cart')) ?></span>
                    <span class="hero__cart-count"
                          aria-label="<?= e(Lang::get('nav.cart.count', ['count' => (int) $cartCount])) ?>"><?= (int) $cartCount ?></span>
                </a>

                <a class="hero__account"
                   href="<?= Shop::customerId() === null ? '/account/login.php' : '/account/' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                              d="M12 12.4a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.8 20a7.2 7.2 0 0 1 14.4 0"/>
                    </svg>
                    <span class="visually-hidden"><?= e(Lang::get(
                        Shop::customerId() === null ? 'nav.signin' : 'nav.account')) ?></span>
                </a>
            </div>
            </div>

            <?php /*
                Takes the place the slide's own call to action used to hold and
                inherits its destination. The label is the site's, not the
                slide's, so data-hero-cta-fixed tells the controller to set the
                href on a slide change and leave the words alone.
            */ ?>
            <a class="hero__cta hero__cta--notch" data-hero-cta data-hero-cta-fixed
               data-hero-notch="bottom"
               href="<?= e($first['cta_url'] ?? '/product.php?slug=classic-tee') ?>"><?=
                e(Lang::get('hero.cta')) ?></a>
        <?php } ?>
    </div>

    <p class="visually-hidden" data-hero-sr><?= e(implode('. ', array_filter([
        $first['tab_word'] ?? '',
        $first['wordmark'] ?? '',
        $first['tagline'] ?? '',
        ...array_map(static fn (array $s): string => ($s['value'] ?? '') . ' ' . ($s['label'] ?? ''), $stats),
    ]))) ?></p>
</section>
