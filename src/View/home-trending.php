<?php

declare(strict_types=1);

/**
 * The trending strip.
 *
 * WHY THIS SECTION CARRIES class="page--catalog"
 *
 * Because the product card's stylesheet is scoped to it. Every rule that shapes
 * the tile, the price pill and the notch between them is written
 * `.page--catalog .product-card__…`, and /assets/js/catalog-cards.js finishes the
 * shape by measuring the rendered element. Copying those rules under a second
 * class name would be two definitions of one card, and the day someone edits the
 * catalogue's copy the home page's would silently stop matching.
 *
 * So the strip borrows the scope instead, and home.css cancels the four
 * page-level properties that come with it -- the 1440px shell, the background,
 * the top padding and the auto margins -- with a two-class selector. The card
 * rules then apply untouched, which is the entire point.
 *
 * WHAT IS NOT HERE
 *
 * No "see all" to an all-products page, because there isn't one: the link goes to
 * the printed cut, which is what a trending strip is mostly made of. No heart and
 * no wishlist -- there is no such feature and an icon promising one is a lie.
 *
 * @var array<int,array<string,mixed>> $trending  hydrated products, in the owner's order
 */

use App\Support\Lang;
?>
<section class="home-section home-trending page--catalog"
         aria-labelledby="trending-heading" data-reveal>
    <header class="section-head section-head--row">
        <div>
            <p class="section-head__eyebrow"><?= e(Lang::get('home.trending.eyebrow')) ?></p>
            <h2 class="section-head__title" id="trending-heading"><?= e(Lang::get('home.trending.title')) ?></h2>
        </div>

        <?php /*
            "More" points at the printed cut rather than at a catalogue of
            everything, which does not exist. The arrow is a glyph inside the
            link, mirrored by the CSS under rtl -- a ← that stayed put would point
            away from the direction the text reads.
        */ ?>
        <a class="link-more" href="/products.php?type=printed">
            <?= e(Lang::get('home.trending.more')) ?>
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                <path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor"
                      stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
    </header>

    <?php /*
        A grid on desktop, a swipeable rail on a phone -- the same markup for
        both, because the difference is entirely overflow and scroll-snap. Doing
        it with one element means there is no duplicated card list and no
        JavaScript deciding which layout the visitor gets.

        role/aria-label on the scroller: it is a horizontally scrollable region on
        small screens, and a keyboard user needs it focusable and named to reach
        the cards past the first. tabindex="0" is what makes the arrow keys work
        there; on desktop, where nothing overflows, it is harmless.
    */ ?>
    <div class="products-grid home-trending__rail" role="group"
         aria-label="<?= e(Lang::get('home.trending.title')) ?>" tabindex="0">
        <?php /*
            No dir on the cell. It carried one while these sections were Arabic
            islands in a left-to-right document and the card had to be pulled back
            to the document's direction, or the price pill and the notch cut for it
            landed on opposite corners of the same tile. The document is Arabic now,
            so the card, the pill and the notch all read the one direction on <html>.
        */ ?>
        <?php foreach ($trending as $index => $product) { ?>
            <div class="home-trending__cell" data-reveal
                 style="--reveal-delay: <?= min(6, (int) $index) * 60 ?>ms">
                <?php require __DIR__ . '/product-card.php'; ?>
            </div>
        <?php } ?>
    </div>
</section>
