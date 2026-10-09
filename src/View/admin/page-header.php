<?php

declare(strict_types=1);

/**
 * A page's title row: optional back circle, title and subtitle, and the
 * page's actions at the inline end.
 *
 * @var string        $title
 * @var ?string       $subtitle
 * @var ?string       $back     href of the back circle, or null for none
 * @var ?callable     $actions  prints the buttons
 */

use App\Support\Lang;

$subtitle ??= null;
$back     ??= null;
$actions  ??= null;
?>
<div class="page-header">
    <?php if ($back !== null) { ?>
        <a class="icon-button icon-button--surface" href="<?= e($back) ?>" aria-label="<?= e(Lang::get('admin.action.back')) ?>">
            <?= admin_icon('back') ?>
        </a>
    <?php } ?>

    <div class="page-header__text">
        <h1 class="page-header__title"><?= e($title) ?></h1>
        <?php if ($subtitle !== null) { ?>
            <p class="page-header__subtitle"><?= e($subtitle) ?></p>
        <?php } ?>
    </div>

    <?php if ($actions !== null) { ?>
        <div class="page-header__actions"><?php $actions(); ?></div>
    <?php } ?>
</div>
