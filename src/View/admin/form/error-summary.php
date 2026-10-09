<?php

declare(strict_types=1);

/**
 * After a refused save: what went wrong, with a link to each field.
 *
 * The same messages also sit under their fields; this list is the way to find
 * them in a long form. admin-forms.js moves focus here when the page loads, so
 * a screen reader hears the problem before anything else.
 *
 * @var array<string,string> $errors   field name => message
 * @var array<string,string> $ids      field name => control id, when it is not "f-<name>"
 */

use App\Support\Format;
use App\Support\Lang;

$ids ??= [];

if ($errors === []) {
    return;
}
?>
<div class="error-summary" role="alert" tabindex="-1" data-error-summary>
    <h2 class="error-summary__title">
        <?= admin_icon('alert') ?>
        <?= e(Format::plural('admin.form.errors_title', count($errors))) ?>
    </h2>
    <ul class="error-summary__list">
        <?php foreach ($errors as $field => $message) {
            $target = $ids[$field] ?? 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $field), '-'); ?>
            <li><a href="#<?= e($target) ?>"><?= e($message) ?></a></li>
        <?php } ?>
    </ul>
    <p class="error-summary__hint"><?= e(Lang::get('admin.form.errors_hint')) ?></p>
</div>
