<?php

declare(strict_types=1);

/**
 * The two doors out of the landing page: printed, and plain.
 *
 * There is no third card and no "everything" link, because there is no
 * everything page -- the catalogue is these two cuts and nothing else. A shopper
 * who wants to see all of it sees all of it in two clicks, and neither of those
 * clicks lands on a grid of mixed cuts that answers nobody's question.
 *
 * THE WHOLE CARD IS THE LINK
 *
 * <a> wraps the image, the heading, the line of copy and the thing that looks
 * like a button. The "button" is a <span>, not a nested <a>, because an anchor
 * inside an anchor is invalid and browsers recover from it by breaking one of
 * them. One link, one target, and the entire tile is a 44px-plus tap target by
 * a wide margin.
 *
 * @var array<string,string> $settings  from HomeSettingsService::settings()
 */

use App\Support\Lang;

$cards = [
    [
        'title' => Lang::get('home.printed.title'),
        'text'  => Lang::get('home.printed.text'),
        'href'  => '/products.php?type=printed',
        'image' => $settings['printed_card_image'],
        /*
         * The first card is above the fold on a phone, so it is the one image on
         * this page that must not be lazy -- and it is a plausible LCP element.
         * fetchpriority tells the browser that before it has finished laying the
         * page out. The second card is below it on every width and stays lazy.
         */
        'eager' => true,
    ],
    [
        'title' => Lang::get('home.plain.title'),
        'text'  => Lang::get('home.plain.text'),
        'href'  => '/products.php?type=plain',
        'image' => $settings['plain_card_image'],
        'eager' => false,
    ],
];
?>
<section class="home-section home-cats" aria-labelledby="cats-heading" data-reveal>
    <header class="section-head">
        <p class="section-head__eyebrow"><?= e(Lang::get('home.cats.eyebrow')) ?></p>
        <h2 class="section-head__title" id="cats-heading"><?= e(Lang::get('home.cats.title')) ?></h2>
        <p class="section-head__lede"><?= e(Lang::get('home.cats.lede')) ?></p>
    </header>

    <div class="home-cats__grid">
        <?php foreach ($cards as $index => $card) { ?>
            <a class="cat-card" href="<?= e($card['href']) ?>" data-reveal
               style="--reveal-delay: <?= (int) $index * 80 ?>ms">
                <span class="cat-card__media">
                    <?php if ($card['image'] !== '') { ?>
                        <?php /*
                            width/height are the ratio the CSS enforces anyway
                            (4:5), stated on the element so the box is reserved
                            before the file arrives. The image is decorative here
                            -- the heading inside the same link says what the card
                            is -- so alt is empty rather than a repeat of it.
                        */ ?>
                        <img src="<?= e($card['image']) ?>" alt=""
                             width="800" height="1000"
                             <?php if ($card['eager']) { ?>
                                 fetchpriority="high" decoding="async"
                             <?php } else { ?>
                                 loading="lazy" decoding="async"
                             <?php } ?>>
                    <?php } else { ?>
                        <?php /*
                            No photograph set. The card keeps its exact size and
                            shows a grey field rather than collapsing, so the
                            section is laid out identically before and after the
                            owner uploads one.
                        */ ?>
                        <span class="cat-card__blank" aria-hidden="true"></span>
                    <?php } ?>

                    <?php /* The scrim, so white type is legible over any photo. */ ?>
                    <span class="cat-card__scrim" aria-hidden="true"></span>
                </span>

                <span class="cat-card__body">
                    <span class="cat-card__title"><?= e($card['title']) ?></span>
                    <span class="cat-card__text"><?= e($card['text']) ?></span>
                    <span class="btn btn--light btn--md cat-card__cta"><?= e(Lang::get('home.cats.cta')) ?></span>
                </span>
            </a>
        <?php } ?>
    </div>
</section>
