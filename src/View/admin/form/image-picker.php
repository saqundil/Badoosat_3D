<?php

declare(strict_types=1);

/**
 * An image that is already on the server, chosen rather than typed.
 *
 * The field this draws still posts a path, because that is what the hero
 * slide stores -- but nobody types it: the button uploads through
 * /admin/upload.php (admin-hero.js) and writes the answer back here. The input
 * stays readable and focusable so the path can be seen, copied and cleared,
 * and so a keyboard reaches everything the mouse does.
 *
 * @var string  $name
 * @var string  $label
 * @var ?string $id
 * @var string  $value       the stored path, or ''
 * @var ?string $help
 * @var ?string $error
 * @var string  $ratio       CSS aspect-ratio of the thumbnail
 * @var bool    $clearable   offer "remove"
 * @var array<string,string|bool> $attrs  extra attributes on the input
 */

use App\Support\Lang;

$id        ??= 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
$value     ??= '';
$help      ??= null;
$error     ??= null;
$ratio     ??= '4 / 3';
$clearable ??= true;
$attrs     ??= [];

$describedBy = array_filter([$help !== null ? $id . '-help' : null, $error !== null ? $id . '-error' : null]);
?>
<div class="form-field form-field--picker<?= $error !== null ? ' form-field--error' : '' ?>" data-field>
    <label class="form-field__label" for="<?= e($id) ?>"><?= e($label) ?></label>

    <div class="picker" data-picker style="--drop-ratio: <?= e($ratio) ?>">
        <span class="picker__frame">
            <img class="picker__preview" alt="" width="320" height="240" data-picker-preview<?= $value !== '' ? ' src="' . e($value) . '"' : ' hidden' ?>>
            <span class="picker__empty"<?= $value !== '' ? ' hidden' : '' ?> data-picker-empty aria-hidden="true"><?= admin_icon('grid') ?></span>
        </span>

        <div class="picker__bar">
            <button type="button" class="button button--secondary button--small" data-upload="<?= e($name) ?>">
                <?= e(Lang::get($value !== '' ? 'admin.form.drop_replace' : 'admin.form.drop_choose')) ?>
            </button>
            <?php if ($clearable) { ?>
                <button type="button" class="button button--ghost button--small" data-picker-clear="<?= e($id) ?>"<?= $value === '' ? ' hidden' : '' ?>>
                    <?= e(Lang::get('admin.form.picker_clear')) ?>
                </button>
            <?php } ?>
        </div>

        <input class="input input--path" type="text" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
               dir="ltr" spellcheck="false" autocomplete="off" translate="no"
               <?= $describedBy !== [] ? ' aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '' ?>
               <?= $error !== null ? ' aria-invalid="true"' : '' ?>
               <?php foreach ($attrs as $k => $v) {
                   if ($v !== false) { echo ' ' . e((string) $k) . ($v === true ? '' : '="' . e((string) $v) . '"'); }
               } ?>>
    </div>

    <?php if ($help !== null) { ?>
        <p class="form-field__help" id="<?= e($id) ?>-help"><?= e($help) ?></p>
    <?php } ?>
    <?php if ($error !== null) { ?>
        <p class="form-field__error" id="<?= e($id) ?>-error"><?= admin_icon('alert') ?><span><?= e($error) ?></span></p>
    <?php } ?>
</div>
