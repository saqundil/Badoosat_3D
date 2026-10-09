<?php

declare(strict_types=1);

/**
 * A titled block of fields that can be folded away.
 *
 * Long forms are split into these instead of one scroll. <details> does the
 * folding with no script and remembers nothing; a section holding an error is
 * opened by the page (pass $open).
 *
 * @var string   $id
 * @var string   $title
 * @var ?string  $help
 * @var callable $body
 * @var bool     $open
 */

$help ??= null;
$open ??= true;
?>
<details class="form-section" id="<?= e($id) ?>"<?= $open ? ' open' : '' ?>>
    <summary class="form-section__head">
        <span class="form-section__titles">
            <span class="form-section__title"><?= e($title) ?></span>
            <?php if ($help !== null) { ?><span class="form-section__help"><?= e($help) ?></span><?php } ?>
        </span>
        <span class="form-section__chevron" aria-hidden="true"><?= admin_icon('forward') ?></span>
    </summary>
    <div class="form-section__body"><?php $body(); ?></div>
</details>
