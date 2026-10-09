<?php

declare(strict_types=1);

/**
 * The logo, as a link home. Included by the hero's navigation notch and by the
 * top bar on every other page. The footer carries its own <img> now, because it
 * needs the wordmark inverted for a charcoal background and this partial has no
 * opinion about colour.
 *
 * @var string $brandClass  class for the anchor
 * @var int    $brandHeight rendered height in CSS pixels
 * @var bool   $brandLink   false renders the mark without wrapping it in a link
 */

use App\Support\Brand;

$brandClass  = $brandClass ?? 'brand';
$brandHeight = $brandHeight ?? Brand::HEIGHT;
$brandLink   = $brandLink ?? true;

$tag = $brandLink ? 'a' : 'span';
?>
<<?= $tag ?> class="<?= e($brandClass) ?>"<?= $brandLink ? ' href="/"' : '' ?>>
    <?php if (Brand::logoExists()) { ?>
        <?php /*
            eager, and no lazy attribute: the mark is above the fold on every
            page, and a deferred logo is a header that fills in after the text
            around it. width/height are the file's own ratio at this height, so
            the box is reserved before the bytes arrive.
        */ ?>
        <img class="brand__logo" src="<?= e(Brand::LOGO . '?v=' . ASSETS) ?>"
             alt="<?= e(Brand::NAME) ?>"
             width="<?= Brand::width($brandHeight) ?>" height="<?= (int) $brandHeight ?>"
             loading="eager" decoding="sync">
    <?php } else { ?>
        <?php /*
            The image file is not in the repository. Rather than ship a broken
            image on every page, the name stands in until it is added at
            public<?= e(Brand::LOGO) ?> -- at which point every place that
            includes this file picks it up with no further change.
        */ ?>
        <span class="brand__wordmark"><?= e(Brand::NAME) ?></span>
    <?php } ?>
</<?= $tag ?>>
