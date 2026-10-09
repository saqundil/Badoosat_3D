<?php

declare(strict_types=1);

/**
 * An image slot: shows what is stored, takes a new one by click, drag or paste.
 *
 * The real control is the <input type="file">, so the form posts exactly what
 * it posted before and the server keeps checking the type with finfo. What
 * admin-forms.js adds is the preview, the drop target, paste, and a warning
 * when the picture is smaller than the slot needs -- a warning, not a refusal:
 * the server decides.
 *
 * @var string  $name
 * @var string  $label
 * @var ?string $id
 * @var ?string $current     URL of the stored image, or null
 * @var ?string $help
 * @var ?string $error
 * @var string  $accept
 * @var int     $minWidth    0 = no hint
 * @var int     $minHeight
 * @var ?string $removeName  checkbox name that clears the stored image
 * @var string  $ratio       CSS aspect-ratio of the slot, e.g. "4 / 5"
 * @var bool    $multiple    several files at once (the name gets [] added); the
 *                           preview shows the first, the status counts them
 * @var array<string,string|bool> $inputAttrs   extra attributes on the file input
 * @var array<string,string|bool> $removeAttrs  extra attributes on the remove box
 */

use App\Support\Format;
use App\Support\Lang;
use App\Support\UploadLimit;

$id         ??= 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
$current    ??= null;
$help       ??= null;
$error      ??= null;
$accept     ??= 'image/jpeg,image/png,image/webp';
$minWidth   ??= 0;
$minHeight  ??= 0;
$removeName ??= null;
$ratio      ??= '4 / 3';
$multiple   ??= false;
$inputAttrs ??= [];
$removeAttrs ??= [];

$extra = static function (array $attrs): string {
    $out = '';
    foreach ($attrs as $key => $value) {
        if ($value === false) {
            continue;
        }
        $out .= ' ' . e((string) $key) . ($value === true ? '' : '="' . e((string) $value) . '"');
    }

    return $out;
};

$limit = UploadLimit::bytes();
?>
<div class="form-field form-field--dropzone<?= $error !== null ? ' form-field--error' : '' ?>" data-field>
    <span class="form-field__label" id="<?= e($id) ?>-label"><?= e($label) ?></span>

    <div class="dropzone<?= $current !== null ? ' dropzone--filled' : '' ?>" data-dropzone
         data-min-width="<?= (int) $minWidth ?>" data-min-height="<?= (int) $minHeight ?>" data-max-bytes="<?= (int) $limit ?>"
         data-msg-small="<?= e(Lang::get('admin.form.drop_small', ['w' => $minWidth, 'h' => $minHeight])) ?>"
         data-msg-big="<?= e(Lang::get('admin.form.drop_big', ['mb' => UploadLimit::mebibytes($limit)])) ?>"
         data-msg-type="<?= e(Lang::get('admin.form.drop_type')) ?>"
         data-msg-chosen="<?= e(Lang::get('admin.form.drop_chosen')) ?>"
         data-msg-many="<?= e(Lang::get('admin.form.drop_many')) ?>"
         style="--drop-ratio: <?= e($ratio) ?>">
        <div class="dropzone__frame">
            <img class="dropzone__preview" alt="" width="800" height="600" data-preview<?= $current !== null ? ' src="' . e($current) . '"' : ' hidden' ?>>
            <div class="dropzone__empty"<?= $current !== null ? ' hidden' : '' ?> data-empty>
                <span class="dropzone__icon"><?= admin_icon('export') ?></span>
                <span><?= e(Lang::get('admin.form.drop_here')) ?></span>
            </div>
        </div>

        <div class="dropzone__bar">
            <label class="button button--secondary button--small" for="<?= e($id) ?>">
                <?= e(Lang::get($current !== null ? 'admin.form.drop_replace' : 'admin.form.drop_choose')) ?>
            </label>
            <input class="dropzone__input" type="file" id="<?= e($id) ?>" name="<?= e($name . ($multiple ? '[]' : '')) ?>" accept="<?= e($accept) ?>"
                   aria-labelledby="<?= e($id) ?>-label" aria-describedby="<?= e($id) ?>-status<?= $help !== null ? ' ' . e($id) . '-help' : '' ?>"<?= $multiple ? ' multiple' : '' ?><?= $extra($inputAttrs) ?>>
            <?php if ($current !== null && $removeName !== null) { ?>
                <label class="check check--small">
                    <input type="checkbox" name="<?= e($removeName) ?>" value="1" data-drop-remove<?= $extra($removeAttrs) ?>>
                    <span class="check__box" aria-hidden="true"></span>
                    <span class="check__text"><?= e(Lang::get('admin.form.drop_remove')) ?></span>
                </label>
            <?php } ?>
        </div>
        <p class="dropzone__status" id="<?= e($id) ?>-status" role="status" data-status></p>
    </div>

    <?php if ($help !== null || $minWidth > 0) { ?>
        <p class="form-field__help" id="<?= e($id) ?>-help">
            <?= e(trim(($help ?? '') . ' ' . ($minWidth > 0 ? Lang::get('admin.form.drop_min', ['w' => Format::number($minWidth), 'h' => Format::number($minHeight)]) : ''))) ?>
        </p>
    <?php } ?>
    <?php if ($error !== null) { ?>
        <p class="form-field__error" id="<?= e($id) ?>-error"><?= admin_icon('alert') ?><span><?= e($error) ?></span></p>
    <?php } ?>
</div>
