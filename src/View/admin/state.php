<?php

declare(strict_types=1);

/**
 * The one look for "nothing here", "could not load" and "loading".
 *
 * An empty state says what would be here and, when there is one, offers the
 * way out (clear the filters). An error says what failed and what to do; the
 * detail is in the PHP log, not on the screen. Loading is a skeleton of the
 * shape that is coming -- see .state--loading and [aria-busy] in admin.css.
 *
 * @var 'empty'|'error'|'loading' $kind
 * @var string  $text
 * @var ?string $hint
 * @var ?array{href:string,text:string} $action
 * @var bool    $compact   inside a card rather than in place of one
 * @var bool    $onDark
 */

$hint    ??= null;
$action  ??= null;
$compact ??= false;
$onDark  ??= false;

$icon = match ($kind) {
    'error'   => 'alert',
    'loading' => 'clock',
    default   => 'inbox',
};
$classes = 'state state--' . $kind . ($compact ? ' state--compact' : '') . ($onDark ? ' state--dark' : '');
?>
<div class="<?= e($classes) ?>"<?= $kind === 'error' ? ' role="alert"' : '' ?><?= $kind === 'loading' ? ' aria-busy="true"' : '' ?>>
    <span class="state__icon"><?= admin_icon($icon) ?></span>
    <p class="state__text"><?= e($text) ?></p>
    <?php if ($hint !== null) { ?><p class="state__hint"><?= e($hint) ?></p><?php } ?>
    <?php if ($action !== null) { ?>
        <a class="button button--secondary" href="<?= e($action['href']) ?>"><?= e($action['text']) ?></a>
    <?php } ?>
</div>
