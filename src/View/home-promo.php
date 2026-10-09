<?php

declare(strict_types=1);

/**
 * The banner over the design editor.
 *
 * Every word and the photograph behind them come from home_settings, so the shop
 * owner changes the campaign without a deploy. The fallbacks are in
 * HomeSettingsService::DEFAULTS, which means this template never asks whether a
 * value is set -- it is always a string, and always one worth printing.
 *
 * The image is the exception, and deliberately: an empty promo_image is not a
 * missing setting but a documented state, in which the banner paints a grey
 * gradient. That is why there is a branch below for the picture and none for the
 * text.
 *
 * THE BUTTON OPENS THE EDITOR THAT ALREADY EXISTS
 *
 * /design.php, the same page the catalogue's invitation card sends people to. No
 * second editor, no second route into the first one.
 *
 * @var array<string,string> $settings  from HomeSettingsService::settings()
 */

use App\Support\Lang;
?>
<section class="home-section home-promo<?= $settings['promo_image'] === '' ? ' home-promo--plain' : '' ?>"
         aria-labelledby="promo-heading" data-reveal>
    <?php if ($settings['promo_image'] !== '') { ?>
        <?php /*
            A real <img> rather than a CSS background, so it can be lazy, can
            carry dimensions, and is fetched at a width the browser chooses. A
            background-image is none of those things and is the usual reason a
            banner like this is the slowest element on a landing page.

            alt is empty: the heading beside it says what this is, and a
            description of the photograph would be read out as a duplicate.
        */ ?>
        <img class="home-promo__bg" src="<?= e($settings['promo_image']) ?>" alt=""
             width="1600" height="700" loading="lazy" decoding="async">
    <?php } ?>

    <?php /* The wash over the photo. Present in both states: with no photo it is
             the gradient itself, which is why it is not inside the branch. */ ?>
    <span class="home-promo__scrim" aria-hidden="true"></span>

    <div class="home-promo__body">
        <p class="home-promo__eyebrow"><?= e(Lang::get('home.promo.eyebrow')) ?></p>
        <h2 class="home-promo__title" id="promo-heading"><?= e($settings['promo_title']) ?></h2>
        <p class="home-promo__text"><?= e($settings['promo_text']) ?></p>
        <a class="btn btn--light btn--lg" href="/design.php"><?= e($settings['promo_button']) ?></a>
    </div>
</section>
