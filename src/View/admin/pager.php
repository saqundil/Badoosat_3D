<?php

declare(strict_types=1);

/**
 * Page links: previous, the first and last pages, the current page with one
 * either side, and a gap for the rest. Shared by the data table and the orders
 * list, so every paged list in the dashboard moves the same way.
 *
 * Nothing is printed for a single page. The optional summary ("21–40 of 104")
 * says where the visible rows sit in the whole.
 *
 * @var int                  $page
 * @var int                  $pages
 * @var callable(int):string $href
 * @var ?string              $summary
 * @var bool                 $onDark
 */

use App\Support\Format;
use App\Support\Lang;

$summary ??= null;
$onDark  ??= false;

if ($pages <= 1 && $summary === null) {
    return;
}
?>
<div class="pager-row<?= $onDark ? ' pager-row--on-dark' : '' ?>">
    <?php if ($summary !== null) { ?>
        <p class="pager-row__summary"><?= e($summary) ?></p>
    <?php } ?>

    <?php if ($pages > 1) {
        $button = $onDark ? 'icon-button--on-dark' : 'icon-button--surface'; ?>
        <nav class="pager" aria-label="<?= e(Lang::get('admin.table.pages')) ?>">
            <?php if ($page > 1) { ?>
                <a class="icon-button <?= $button ?>" href="<?= e($href($page - 1)) ?>" rel="prev" aria-label="<?= e(Lang::get('admin.table.prev')) ?>"><?= admin_icon('back') ?></a>
            <?php } ?>
            <?php for ($n = 1; $n <= $pages; $n++) { ?>
                <?php if ($n === $page) { ?>
                    <span class="pager__page" aria-current="page"><?= e(Format::number($n)) ?></span>
                <?php } elseif ($n === 1 || $n === $pages || abs($n - $page) <= 1) { ?>
                    <a class="pager__page" href="<?= e($href($n)) ?>" aria-label="<?= e(Lang::get('admin.table.page', ['n' => Format::number($n)])) ?>"><?= e(Format::number($n)) ?></a>
                <?php } elseif (abs($n - $page) === 2) { ?>
                    <span class="pager__gap" aria-hidden="true">…</span>
                <?php } ?>
            <?php } ?>
            <?php if ($page < $pages) { ?>
                <a class="icon-button <?= $button ?>" href="<?= e($href($page + 1)) ?>" rel="next" aria-label="<?= e(Lang::get('admin.table.next')) ?>"><?= admin_icon('forward') ?></a>
            <?php } ?>
        </nav>
    <?php } ?>
</div>
