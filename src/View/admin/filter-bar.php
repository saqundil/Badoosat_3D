<?php

declare(strict_types=1);

/**
 * "Active filters": a count, the fields as pills, apply and clear.
 *
 * A plain GET form, so a filtered list is a URL that can be bookmarked or
 * sent to someone. $keep are the query parameters the form must carry through
 * (the tab), so filtering never resets the rest of the page.
 *
 * @var string $action
 * @var list<array{type:'date'|'search',name:string,label:string,value:string,placeholder?:string}> $fields
 * @var array<string,string> $keep
 * @var string $clearHref
 */

use App\Support\Format;
use App\Support\Lang;

$keep ??= [];
$active = count(array_filter($fields, static fn (array $f): bool => $f['value'] !== ''));
?>
<form class="filter-bar" method="get" action="<?= e($action) ?>" role="search"
      aria-label="<?= e(Lang::get('admin.filters.label')) ?>">
    <p class="filter-bar__title">
        <?= e(Lang::get('admin.filters.active')) ?>
        <span class="count-badge<?= $active > 0 ? ' count-badge--on' : '' ?>"><?= e(Format::number($active)) ?></span>
    </p>

    <?php foreach ($keep as $name => $value) { ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php } ?>

    <?php foreach ($fields as $field) {
        $id = 'filter-' . $field['name'];
        ?>
        <label class="pill-field pill-field--<?= e($field['type']) ?>" for="<?= e($id) ?>">
            <span class="pill-field__label"><?= e($field['label']) ?></span>
            <input id="<?= e($id) ?>" name="<?= e($field['name']) ?>"
                   type="<?= $field['type'] === 'date' ? 'date' : 'search' ?>"
                   value="<?= e($field['value']) ?>"
                   <?= $field['type'] === 'search' ? 'dir="auto" autocomplete="off" spellcheck="false"' : '' ?>
                   <?= isset($field['placeholder']) ? 'placeholder="' . e($field['placeholder']) . '"' : '' ?>>
            <?php /* A date input draws its own calendar button; a second icon would be a decoy. */ ?>
            <?= $field['type'] === 'search' ? admin_icon('search') : '' ?>
        </label>
    <?php } ?>

    <div class="filter-bar__actions">
        <button type="submit" class="button button--secondary"><?= e(Lang::get('admin.filters.apply')) ?></button>
        <?php if ($active > 0) { ?>
            <a class="button button--ghost" href="<?= e($clearHref) ?>"><?= e(Lang::get('admin.filters.clear')) ?></a>
        <?php } ?>
    </div>
</form>
