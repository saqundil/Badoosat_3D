<?php

declare(strict_types=1);

/**
 * One form field: label above, the control, help text, the error.
 *
 * Every control in the dashboard is drawn by this partial, so a label, a
 * required mark, a hint and an error look and read the same on every page.
 * Strings arrive translated -- this file never names a word of its own except
 * through Lang.
 *
 * ERRORS, TWO KINDS
 *
 * $error is the server's sentence for this field after a refused save; it is
 * shown at once and the control is marked aria-invalid. $invalid is the
 * sentence for a constraint the browser checks (required, a pattern, a range);
 * it stays hidden until the control is :user-invalid, i.e. until the person has
 * actually interacted with it, never on page load.
 *
 * @var string  $type     text|email|tel|url|search|password|number|money|textarea|select|date|
 *                        toggle|checklist|radios|segmented|swatches
 * @var string  $name
 * @var string  $label
 * @var ?string $id
 * @var mixed   $value     scalar, or a list of values for checklist
 * @var ?string $help
 * @var ?string $error     server-side message for this field
 * @var ?string $invalid   message shown once the browser finds the value invalid
 * @var bool    $required
 * @var ?int    $maxlength counter shown when set
 * @var ?string $unit      suffix for number fields (cm, %, …)
 * @var ?string $dir       'auto' for free text that may be in either script
 * @var array<int,array{value:string|int,label:string,hint?:string,hex?:string,disabled?:bool,reason?:string}> $options
 * @var array<string,string|int|bool> $attrs extra attributes on the control
 * @var bool    $hideLabel  keep the label for screen readers only
 */

use App\Support\Format;

$id        ??= 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
$value     ??= '';
$help      ??= null;
$error     ??= null;
$invalid   ??= null;
$required  ??= false;
$maxlength ??= null;
$unit      ??= null;
$dir       ??= null;
$options   ??= [];
$attrs     ??= [];
$hideLabel ??= false;

$group = in_array($type, ['toggle', 'checklist', 'radios', 'segmented', 'swatches'], true);

$describedBy = [];
if ($help !== null) {
    $describedBy[] = $id . '-help';
}
if ($error !== null) {
    $describedBy[] = $id . '-error';
}
if ($invalid !== null && $error === null) {
    $describedBy[] = $id . '-invalid';
}

/** Attribute string for the control: what this partial knows, then the page's extras. */
$attr = static function (array $extra = []) use ($id, $name, $required, $maxlength, $dir, $describedBy, $error, $attrs): string {
    $all = ['id' => $id, 'name' => $name] + $extra;

    if ($required) {
        $all['required'] = true;
    }
    if ($maxlength !== null) {
        $all['maxlength'] = $maxlength;
    }
    if ($dir !== null) {
        $all['dir'] = $dir;
    }
    if ($describedBy !== []) {
        $all['aria-describedby'] = implode(' ', $describedBy);
    }
    if ($error !== null) {
        $all['aria-invalid'] = 'true';
    }

    $out = '';
    foreach ($all + $attrs as $key => $val) {
        if ($val === false || $val === null) {
            continue;
        }
        $out .= ' ' . e((string) $key) . ($val === true ? '' : '="' . e((string) $val) . '"');
    }

    return $out;
};

$classes = 'form-field form-field--' . $type
    . ($error !== null ? ' form-field--error' : '')
    . ($hideLabel ? ' form-field--bare' : '');
$labelText = e($label) . ($required ? ' <span class="form-field__req" aria-hidden="true">*</span>' : '');
?>
<div class="<?= e($classes) ?>" data-field>
<?php if ($group && $type !== 'toggle') { ?>
    <fieldset class="form-field__group"<?= $describedBy !== [] ? ' aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '' ?>>
        <legend class="form-field__label<?= $hideLabel ? ' visually-hidden' : '' ?>"><?= $labelText ?></legend>

        <?php if ($type === 'checklist') {
            $chosen = array_map('strval', (array) $value); ?>
            <div class="checklist">
                <?php foreach ($options as $i => $option) {
                    $oid = $id . '-' . $i; ?>
                    <label class="check" for="<?= e($oid) ?>">
                        <input type="checkbox" id="<?= e($oid) ?>" name="<?= e($name) ?>[]" value="<?= e((string) $option['value']) ?>"
                            <?= in_array((string) $option['value'], $chosen, true) ? ' checked' : '' ?><?= !empty($option['disabled']) ? ' disabled' : '' ?>>
                        <span class="check__box" aria-hidden="true"></span>
                        <span class="check__text"><?= e($option['label']) ?></span>
                    </label>
                <?php } ?>
            </div>

        <?php } elseif ($type === 'radios') { ?>
            <div class="radio-cards">
                <?php foreach ($options as $i => $option) {
                    $oid = $id . '-' . $i; ?>
                    <label class="radio-card" for="<?= e($oid) ?>">
                        <input type="radio" id="<?= e($oid) ?>" name="<?= e($name) ?>" value="<?= e((string) $option['value']) ?>"
                            <?= (string) $value === (string) $option['value'] ? ' checked' : '' ?><?= $required ? ' required' : '' ?><?= !empty($option['disabled']) ? ' disabled' : '' ?>>
                        <span class="radio-card__title"><?= e($option['label']) ?></span>
                        <?php if (!empty($option['hint'])) { ?><span class="radio-card__hint"><?= e($option['hint']) ?></span><?php } ?>
                    </label>
                <?php } ?>
            </div>

        <?php } elseif ($type === 'segmented') { ?>
            <div class="segmented">
                <?php foreach ($options as $i => $option) {
                    $oid = $id . '-' . $i; ?>
                    <input class="segmented__input" type="radio" id="<?= e($oid) ?>" name="<?= e($name) ?>" value="<?= e((string) $option['value']) ?>"
                        <?= (string) $value === (string) $option['value'] ? ' checked' : '' ?>>
                    <label class="segmented__option" for="<?= e($oid) ?>"><?= e($option['label']) ?></label>
                <?php } ?>
            </div>

        <?php } else { /* swatches */ ?>
            <div class="swatches">
                <?php foreach ($options as $i => $option) {
                    $oid = $id . '-' . $i;
                    $why = !empty($option['disabled']) ? (string) ($option['reason'] ?? '') : ''; ?>
                    <label class="swatch-pick" for="<?= e($oid) ?>" title="<?= e($option['label'] . ($why !== '' ? ' — ' . $why : '')) ?>">
                        <input type="radio" id="<?= e($oid) ?>" name="<?= e($name) ?>" value="<?= e((string) $option['value']) ?>"
                            <?= (string) $value === (string) $option['value'] ? ' checked' : '' ?><?= $why !== '' ? ' disabled' : '' ?>>
                        <span class="swatch-pick__dot" style="--swatch: <?= e((string) ($option['hex'] ?? '#ffffff')) ?>" aria-hidden="true"></span>
                        <span class="visually-hidden"><?= e($option['label'] . ($why !== '' ? ' — ' . $why : '')) ?></span>
                    </label>
                <?php } ?>
            </div>
        <?php } ?>
    </fieldset>

<?php } elseif ($type === 'toggle') { ?>
    <label class="toggle" for="<?= e($id) ?>">
        <input type="checkbox" role="switch" value="1"<?= $attr() ?><?= $value ? ' checked' : '' ?>>
        <span class="toggle__track" aria-hidden="true"><span class="toggle__thumb"></span></span>
        <span class="toggle__text<?= $hideLabel ? ' visually-hidden' : '' ?>"><?= e($label) ?></span>
    </label>

<?php } else { ?>
    <label class="form-field__label<?= $hideLabel ? ' visually-hidden' : '' ?>" for="<?= e($id) ?>"><?= $labelText ?></label>

    <?php if ($type === 'textarea') { ?>
        <textarea class="input input--area"<?= $attr(['rows' => $attrs['rows'] ?? 3]) ?>><?= e((string) $value) ?></textarea>

    <?php } elseif ($type === 'select') { ?>
        <span class="select">
            <select class="input input--select"<?= $attr() ?>>
                <?php foreach ($options as $option) { ?>
                    <option value="<?= e((string) $option['value']) ?>"<?= (string) $value === (string) $option['value'] ? ' selected' : '' ?><?= !empty($option['disabled']) ? ' disabled' : '' ?>><?= e($option['label']) ?></option>
                <?php } ?>
            </select>
        </span>

    <?php } elseif ($type === 'money') {
        // The mark sits where the locale writes it: before the figure or after.
        $parts = Format::moneyParts(0); ?>
        <span class="affix<?= $parts['before'] ? ' affix--before' : '' ?>">
            <input class="input" type="text" inputmode="decimal" autocomplete="off" pattern="[0-9]+([.][0-9]{1,2})?"<?= $attr(['value' => (string) $value]) ?>>
            <span class="affix__text" aria-hidden="true"><?= e($parts['symbol']) ?></span>
        </span>

    <?php } elseif ($type === 'number' && $unit !== null) { ?>
        <span class="affix">
            <input class="input" type="number"<?= $attr(['value' => (string) $value]) ?>>
            <span class="affix__text" aria-hidden="true"><?= e($unit) ?></span>
        </span>

    <?php } else {
        // Addresses, numbers and links are not words: no spellcheck, no capitals.
        $literal = in_array($type, ['email', 'tel', 'url'], true)
            ? ['spellcheck' => 'false', 'autocapitalize' => 'off'] : []; ?>
        <input class="input" type="<?= e($type) ?>"<?= $attr(['value' => (string) $value] + $literal) ?>>
    <?php } ?>
<?php } ?>

<?php if ($maxlength !== null) { ?>
    <p class="form-field__count" data-count-for="<?= e($id) ?>" aria-live="off">
        <span data-count><?= e(Format::number(mb_strlen((string) $value))) ?></span> / <?= e(Format::number($maxlength)) ?>
    </p>
<?php } ?>
<?php if ($help !== null) { ?>
    <p class="form-field__help" id="<?= e($id) ?>-help"><?= e($help) ?></p>
<?php } ?>
<?php if ($error !== null) { ?>
    <p class="form-field__error" id="<?= e($id) ?>-error"><?= admin_icon('alert') ?><span><?= e($error) ?></span></p>
<?php } elseif ($invalid !== null) { ?>
    <p class="form-field__error form-field__error--client" id="<?= e($id) ?>-invalid"><?= admin_icon('alert') ?><span><?= e($invalid) ?></span></p>
<?php } ?>
</div>
