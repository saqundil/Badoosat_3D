<?php

declare(strict_types=1);

/**
 * One product, as a card.
 *
 * Lifted out of catalog.php unchanged so the home page's trending strip and the
 * two cut listings render the same card rather than three that look alike until
 * one of them is edited. The image tile, the price pill and the notch between
 * them are byte for byte what the catalogue rendered before this file existed,
 * and /assets/js/catalog-cards.js still finishes the shape by measuring
 * .product-card__media-wrap -- which is why the pill stays a SIBLING of
 * .product-card__media and not a child of it: it sits in the very region the
 * clip-path removes, so clipping its own container would erase it.
 *
 * DO NOT change the tile's aspect ratio, the pill's position, its typeface or
 * its colour here. Every length of both is written onto the element by that
 * script, from the tile's measured width; the stylesheet reads those values
 * back and does not set them. A value stated here would be overwritten on the
 * first resize and would look like an intermittent fault.
 *
 * The caption below the tile -- the name, the cut, the summary, the swatches --
 * is ordinary markup and is the part that may be dressed.
 *
 * @var array<string,mixed> $product  one hydrated row from ProductService
 */

use App\Support\Lang;
use App\Support\Price;
?>
<a class="product-card" href="/product.php?slug=<?= e(rawurlencode($product['slug'])) ?>">
    <?php /*
        The wrapper is what the price hangs off. The clip that rounds the photo
        lives one level in, on __media, so the pill can cross the bottom edge
        instead of being cut off by it.
    */ ?>
    <span class="product-card__media-wrap">
        <span class="product-card__media">
            <?php if ($product['image']['src'] !== '') { ?>
                <img src="<?= e($product['image']['src']) ?>"
                     <?php if ($product['image']['srcset'] !== '') { ?>
                         srcset="<?= e($product['image']['srcset']) ?>"
                         sizes="<?= e($product['image']['sizes']) ?>"
                     <?php } ?>
                     alt="<?= e($product['name']) ?>"
                     width="600" height="800" loading="lazy" decoding="async">
            <?php } else { ?>
                <?php /*
                    No photo on disk yet. The tile keeps its size and its clip
                    so the row does not jump, and says which product is missing
                    one rather than showing a blank.
                */ ?>
                <span class="product-card__placeholder" aria-hidden="true"><?= e($product['name']) ?></span>
            <?php } ?>
        </span>

        <?php /*
            The inner span is what catalog-cards.js measures and scales: the
            pill around it is a fixed box, so a long price is set smaller rather
            than made wider.
        */ ?>
        <?php if ((int) $product['min_price'] > 0) { ?>
            <span class="product-card__price"><span class="product-card__price-value"><?= e(Price::badge((int) $product['min_price'])) ?></span></span>
        <?php } ?>
    </span>

    <?php /*
        No rating here: the database has no rating column, and the star that
        used to sit on this line was the same hardcoded "جديد" on every product,
        which is not information. It comes back when reviews do.
    */ ?>
    <span class="product-card__title">
        <span class="product-card__name"><?= e($product['name']) ?></span>
        <?php if (!$product['in_stock']) { ?>
            <span class="product-card__sold"><?= e(Lang::get('card.soldout')) ?></span>
        <?php } ?>
    </span>

    <?php /*
        The cut, then the summary. Two products can share a photograph and a
        description and still be different garments -- a basic cut and a heavier
        graphic cut hang differently and fit differently -- and that is the thing
        a shopper is choosing between before they reach the page.
    */ ?>
    <span class="product-card__meta">
        <?php if (!empty($product['garment_name'])) { ?>
            <span class="product-card__cut"><?= e($product['garment_name']) ?></span>
        <?php } ?>
        <?= e($product['summary']) ?>
    </span>

    <?php if ($product['colors'] !== []) { ?>
        <span class="product-card__swatches" aria-label="<?= e(Lang::get('card.colors')) ?>">
            <?php foreach ($product['colors'] as $color) { ?>
                <span class="product-card__swatch" style="background:<?= e($color['hex']) ?>"
                      title="<?= e($color['name']) ?>"></span>
            <?php } ?>
        </span>
    <?php } ?>
</a>
