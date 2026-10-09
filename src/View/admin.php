<?php

declare(strict_types=1);

/**
 * @var string   $title
 * @var string   $current
 * @var bool     $importmap
 * @var callable $body
 * @var ?string  $extraCss
 * @var ?array{text:string,kind?:string} $toast  one message for the toast region
 */

use App\Support\Lang;

require_once __DIR__ . '/admin/helpers.php';

$title     = $title ?? Lang::get('admin.title');
$current   = $current ?? '';
$importmap = $importmap ?? false;
$toast     = $toast ?? null;
?>
<!DOCTYPE html>
<?php /*
    The dashboard's language is the administrator's (?lang=, remembered in the
    session -- see Lang::applyAdminChoice); the direction follows from it. One
    stylesheet serves both: admin.css is written in logical properties.
*/ ?>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= Lang::isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(Lang::get('admin.title')) ?></title>
    <?php /*
        tokens.css first. admin.css points every one of its --admin-* variables
        at a token now, so without this file the panel would render with no
        colours at all rather than with the wrong ones. hero.css comes next for
        the Cairo @font-face rules and the --font-body the panel inherits; it is
        not otherwise used here.
    */ ?>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/hero.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= e(ASSETS) ?>">

    <?php if ($importmap) { ?>
        <script type="importmap">
        {"imports":{
          "three": "/assets/js/vendor/three/build/three.module.js",
                      "three/addons/controls/OrbitControls.js": "/assets/js/vendor/three/examples/jsm/loaders/OrbitControls.js",
          "three/addons/": "/assets/js/vendor/three/examples/jsm/"
        }}
        </script>
    <?php } ?>
</head>
<body class="admin">

<a class="skip-link" href="#content"><?= e(Lang::get('admin.skip')) ?></a>

<?php admin_partial('nav', ['current' => $current]); ?>

<div id="content" class="admin__content" tabindex="-1">
    <?php $body(); ?>
</div>

<?php /*
    Toasts: bottom-end, polite. A page hands one message over in $toast (after a
    save, for instance); admin-forms.js adds more through window.adminToast().
*/ ?>
<div class="toasts" data-toasts role="status" aria-live="polite"
     data-close-label="<?= e(Lang::get('admin.toast.close')) ?>" data-close-icon="<?= e(admin_icon('close')) ?>">
    <?php if ($toast !== null) {
        $kind = ($toast['kind'] ?? 'ok') === 'error' ? 'error' : 'ok'; ?>
        <div class="toast toast--<?= $kind ?>"<?= $kind === 'error' ? ' role="alert"' : '' ?>>
            <p class="toast__text"><?= e($toast['text']) ?></p>
            <button type="button" class="toast__close" data-toast-close aria-label="<?= e(Lang::get('admin.toast.close')) ?>"><?= admin_icon('close') ?></button>
        </div>
    <?php } ?>
</div>

<?php /* Each file only acts where its data-* hooks are on the page. Separate
         tags rather than imports, so every one carries the ?v= cache-buster. */ ?>
<script type="module" src="/assets/js/admin-ui.js?v=<?= e(ASSETS) ?>"></script>
<script type="module" src="/assets/js/admin-forms.js?v=<?= e(ASSETS) ?>"></script>
<script type="module" src="/assets/js/admin-table.js?v=<?= e(ASSETS) ?>"></script>
<script type="module" src="/assets/js/admin-studio.js?v=<?= e(ASSETS) ?>"></script>
</body>
</html>
