<?php

declare(strict_types=1);

/*
 * The slide editor's preview, as a document of its own.
 *
 * It is in a frame rather than in the editor's column for one reason that no
 * amount of scaling solves: the hero's shape is decided by viewport media
 * queries -- the links fold at 1100, the card shortens at 820, the two top
 * notches become one band at 600 -- and a div inside a 1000px panel is at a
 * 1680px viewport whatever width it is drawn at. Only a frame has a viewport
 * of its own, so only a frame can be 390px wide in the way a phone is.
 *
 * Two things follow for free. The frame reports its own width to JS, so the
 * notches and the S are computed from 1440 rather than from the panel; and the
 * document carries its own dir, so the preview is not dragged right-to-left by
 * the Arabic admin panel around it.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HeroSlideService;
use App\Support\Auth;
use App\Support\Lang;

// The frame's ?lang= previews a direction; it must not switch the panel's.
Auth::require(adminLocale: false);

$service = HeroSlideService::make();

$id    = (int) ($_GET['id'] ?? 0);
$slide = $id > 0 ? $service->find($id) : null;
$slide ??= $service->blank();

/* The site's own locale is the default; the switch above the frame is what
   moves it, and it moves nothing outside this document. */
$locale = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
Lang::useLocale($locale);

$slides    = [$slide];
$garment   = HeroSlideService::withGarment($slides)['garment'];
$auto      = false;
$chrome    = true;
$label     = 'معاينة الشريحة';
$cartCount = 0;
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" dir="<?= $locale === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>معاينة</title>
    <link rel="stylesheet" href="/assets/css/hero.css?v=<?= e(ASSETS) ?>">

    <style>
        /*
         * The page around the card, and nothing else. Every measurement the
         * card makes is the component's own -- a second set here is what made
         * the preview and the site disagree before any of this existed.
         */
        html, body { margin: 0; padding: 0; background: var(--paper); }
        body { overflow-x: hidden; }

        /* Drag handles. The same outlines the editor drew when the preview
           lived in the panel; they belong to this document now. */
        .is-handle { pointer-events: auto; cursor: grab; touch-action: none;
                     outline: 1px dashed rgba(233, 196, 0, 0.55); outline-offset: 6px; }
        .is-handle:hover { outline-style: solid; }
        .is-handle.is-dragging { cursor: grabbing; outline: 2px solid #e9c400; }

        /*
         * The chrome is a picture of the chrome. Real links and a real button,
         * so a mis-aimed click would leave the editor; inert, so it cannot.
         */
        .hero__notch, .hero__cta--notch { pointer-events: none; }

        /* The canvas would swallow a drag aimed at the wordmark behind it. */
        .hero__stage { pointer-events: none; }
    </style>

    <script type="importmap">
    {"imports":{
      "three": "/assets/js/vendor/three/build/three.module.js",
      "three/addons/": "/assets/js/vendor/three/examples/jsm/"
    }}
    </script>
</head>
<body class="has-hero">

<div class="frame">
    <?php require dirname(__DIR__, 2) . '/src/View/hero.php'; ?>
</div>

<?php /* Cuts the notches. Without it the chrome would float with no holes. */ ?>
<script type="module" src="/assets/js/hero-notches.js?v=<?= e(ASSETS) ?>"></script>
<script type="module" src="/assets/js/hero-preview.js?v=<?= e(ASSETS) ?>"></script>

</body>
</html>
