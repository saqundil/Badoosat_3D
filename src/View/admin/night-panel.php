<?php

declare(strict_types=1);

/**
 * The dark panel, with its tabs sitting in a notch cut into its top edge.
 *
 * THREE SIBLINGS, ONE CLIPPED
 *
 *   .night-panel__bg    empty, aria-hidden, carries the colour and the
 *                       clip-path: shape() that cuts the notch
 *   .night-panel__tabs  the tabs, placed in the hole with inset-inline-start
 *   .night-panel__body  everything readable
 *
 * The cut-out is a real hole, so nothing that must be seen can live inside the
 * clipped element -- that is why the colour is on a layer of its own. shape()
 * has no logical coordinates; the path is drawn once for left-to-right and the
 * empty layer is mirrored with one :dir(rtl) rule, which flips no text because
 * there is none in it. The tabs follow the mirrored hole by themselves, being
 * placed logically. Without shape() support, and under 768px, the layer is a
 * plain rounded panel and the tabs are a pill row above it. admin-ui.js
 * measures the tabs into --notch-w so a longer translation widens the hole.
 *
 * @var string   $title
 * @var ?string  $count     formatted number beside the title
 * @var list<array{key:string,label:string,count:string,href:string,current:bool}> $tabs
 * @var string   $tabsLabel
 * @var ?callable $tools    circle buttons at the head's inline end
 * @var callable $body
 */

$count ??= null;
$tools ??= null;
?>
<section class="night-panel" data-notch aria-labelledby="night-panel-title">
    <div class="night-panel__bg" aria-hidden="true"></div>

    <nav class="night-panel__tabs" aria-label="<?= e($tabsLabel) ?>" data-notch-tabs>
        <ul class="tabs">
            <?php foreach ($tabs as $tab) { ?>
                <li>
                    <a class="tabs__tab" href="<?= e($tab['href']) ?>"<?= $tab['current'] ? ' aria-current="page"' : '' ?>>
                        <?= e($tab['label']) ?>
                        <span class="tabs__count"><?= e($tab['count']) ?></span>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </nav>

    <div class="night-panel__body">
        <header class="night-panel__head">
            <h2 class="night-panel__title" id="night-panel-title">
                <?= e($title) ?>
                <?php if ($count !== null) { ?><span class="night-panel__count"><?= e($count) ?></span><?php } ?>
            </h2>
            <?php if ($tools !== null) { ?>
                <div class="night-panel__tools"><?php $tools(); ?></div>
            <?php } ?>
        </header>

        <?php $body(); ?>
    </div>
</section>
