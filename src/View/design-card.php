<?php

declare(strict_types=1);

/**
 * The invitation, dropped into the product grid.
 *
 * It is a card among cards on purpose -- the same size, the same place in the
 * reading order -- but its image tile is the only tinted one, so the eye finds
 * it without a banner having to shout.
 *
 * One door now, not two. It used to offer the editor and the upload page side
 * by side, and the editor half pointed at a product page -- because that is
 * where the editor was mounted before it had a page of its own. It has one now,
 * /design.php, and that page carries the print-ready upload route as its own
 * second option. So the card sends people to one place and lets that place
 * branch.
 */

use App\Support\Lang;
?>
<div class="product-card product-card--design">
    <span class="product-card__media-wrap">
        <span class="product-card__media product-card__media--design" aria-hidden="true">
            <svg viewBox="0 0 48 48" width="48" height="48" fill="none"
                 stroke="currentColor" stroke-width="2.4"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M24 32V8"></path>
                <path d="M15 17l9-9 9 9"></path>
                <path d="M8 30v6a4 4 0 0 0 4 4h24a4 4 0 0 0 4-4v-6"></path>
            </svg>
        </span>
    </span>

    <span class="product-card__title">
        <span class="product-card__name"><?= e(Lang::get('card.design.title')) ?></span>
    </span>

    <span class="product-card__meta"><?= e(Lang::get('card.design.text')) ?></span>

    <span class="product-card__actions">
        <a class="button button--primary" href="/design.php"><?= e(Lang::get('card.design.title')) ?></a>
    </span>
</div>
