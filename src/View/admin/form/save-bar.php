<?php

declare(strict_types=1);

/**
 * The bar that appears when a form has changes nobody has saved.
 *
 * It is rendered hidden and admin-forms.js shows it the moment the form
 * differs from what the page loaded with, and hides it again if the person
 * undoes their own change. Discard puts every control back; Save submits the
 * form through its existing POST handler, CSRF token and all.
 *
 * @var string  $form   id of the <form> it saves
 * @var ?string $save   label of the save button (default: "Save changes")
 */

use App\Support\Lang;

$save ??= Lang::get('admin.form.save');
?>
<div class="save-bar" data-save-bar="<?= e($form) ?>" hidden>
    <p class="save-bar__text" role="status">
        <span class="save-bar__dot" aria-hidden="true"></span>
        <?= e(Lang::get('admin.form.unsaved')) ?>
    </p>
    <div class="save-bar__actions">
        <button type="button" class="button button--secondary" data-discard="<?= e($form) ?>"><?= e(Lang::get('admin.form.discard')) ?></button>
        <button type="submit" class="button button--primary" form="<?= e($form) ?>"><?= e($save) ?></button>
    </div>
</div>
