<?php

declare(strict_types=1);

/**
 * A confirmation that says exactly what will happen, before it happens.
 *
 * The trigger is the page's own submit button with data-dialog-open="<id>"
 * added: without JavaScript it simply submits, with JavaScript the click opens
 * this dialog instead. The confirming button submits the same form
 * (form="<form>"), so the POST handler, its CSRF field and its redirect are the
 * ones the page already had. Keep the action in hidden inputs, not on the
 * trigger's name/value: the dialog's button is the one that submits.
 *
 * @var string  $id
 * @var string  $title     the question, naming the thing: "Delete the slide «Oversize»?"
 * @var string  $text      what is lost and what is not
 * @var string  $confirm   label of the confirming button: names the action
 * @var ?string $form      id of the form to submit; null for a dialog that only informs
 * @var bool    $danger
 */

use App\Support\Lang;

$form   ??= null;
$danger ??= true;
?>
<dialog class="dialog" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-title" aria-describedby="<?= e($id) ?>-text">
    <div class="dialog__body">
        <span class="dialog__icon<?= $danger ? ' dialog__icon--danger' : '' ?>"><?= admin_icon('alert') ?></span>
        <h2 class="dialog__title" id="<?= e($id) ?>-title"><?= e($title) ?></h2>
        <p class="dialog__text" id="<?= e($id) ?>-text"><?= e($text) ?></p>
    </div>
    <div class="dialog__actions">
        <button type="button" class="button button--secondary" data-dialog-close autofocus><?= e(Lang::get('admin.form.cancel')) ?></button>
        <?php if ($form !== null) { ?>
            <button type="submit" class="button <?= $danger ? 'button--danger-solid' : 'button--primary' ?>" form="<?= e($form) ?>"><?= e($confirm) ?></button>
        <?php } ?>
    </div>
</dialog>
