<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var string $current  nav key of the page being rendered
 * @var ?string $poster  preloaded when the page carries the hero
 * @var bool   $importmap
 * @var bool   $hasHero
 * @var bool   $heroScript
 * @var int    $cartCount
 * @var array<int,string> $styles  page stylesheets, loaded after the shared ones
 * @var ?callable $head  extra <head> content: sharing tags, structured data
 * @var callable $body
 */

use App\Support\Config;
use App\Support\Lang;

$title     = $title ?? Config::get('app.name');
$current   = $current ?? '';
$poster    = $poster ?? null;
$importmap = $importmap ?? false;
$hasHero   = $hasHero ?? $importmap;
$heroScript = $heroScript ?? $hasHero;
$cartCount = $cartCount ?? 0;
$head      = $head ?? null;
/** @var array<int,string> $styles  extra stylesheets, by filename under assets/css */
$styles    = $styles ?? [];

/*
 * The same three destinations the hero's navigation notch carries, for the
 * pages that have no hero to carry them.
 */
$nav = [
    'plain'    => '/catalog.php?type=plain',
    'printed'  => '/catalog.php?type=printed',
    'about'    => '/#about',
];

/*
 * Which link is the page being rendered.
 *
 * Matched on the destination rather than on $current: two of the three links
 * are now the same script with different query strings, and a page that says
 * only "catalog" cannot say which of them it is. Every page keeps setting
 * $current, and it is still what a page passes to name itself, but deciding
 * from the URL means catalog.php did not have to learn about this.
 */
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestType = isset($_GET['type']) ? (string) $_GET['type'] : null;

$isCurrent = static function (string $href) use ($requestPath, $requestType): bool {
    $parts = parse_url($href);
    $path  = $parts['path'] ?? '';

    if ($path === '' || $path !== $requestPath) {
        return false;
    }

    parse_str($parts['query'] ?? '', $query);

    return !isset($query['type']) || $query['type'] === $requestType;
};
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= Lang::isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>

    <?php if ($poster !== null) { ?>
        <link rel="preload" as="image" href="<?= e($poster) ?>" fetchpriority="high">
    <?php } ?>
        <link rel="preload" as="font" type="font/woff2" crossorigin
            href="/assets/fonts/cairo-latin-900-normal.woff2">
    <?php /*
        Every asset carries a version. Without one a stylesheet edited after
        launch stays in a returning visitor's cache for as long as the Expires
        header says -- a week, here -- and the page they see is not the page
        that was deployed. ASSETS is bumped on release.
    */ ?>
    <?php /*
        tokens.css is first, and has to be: it declares variables only, and the
        two files after it are allowed to win. hero.css and store.css own the
        hero, the navbar and the product card, and a token that overrode one of
        theirs would move something this work is not allowed to move. Every name
        in tokens.css is therefore one neither of them declares.
    */ ?>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/hero.css?v=<?= e(ASSETS) ?>">
    <link rel="stylesheet" href="/assets/css/store.css?v=<?= e(ASSETS) ?>">

    <?php /*
        base.css is after store.css and on every page: it holds the components the
        new sections share -- the button, the section heading, the scroll reveal,
        the footer -- and the footer is on every page, so it is not optional. It
        declares no selector either of the two files above it declares, so the
        order matters only for the values it adds.
    */ ?>
    <link rel="stylesheet" href="/assets/css/base.css?v=<?= e(ASSETS) ?>">

    <?php /*
        A page's own stylesheet, last, so it can override the shared ones. Only
        the pages that have new sections carry one -- there is no reason for a
        cart page to fetch the home page's CSS.
    */ ?>
    <?php foreach ($styles as $style) { ?>
        <link rel="stylesheet" href="/assets/css/<?= e($style) ?>?v=<?= e(ASSETS) ?>">
    <?php } ?>

    <?php if ($head !== null) { $head(); } ?>

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
<body<?= $hasHero ? ' class="has-hero"' : '' ?>>

<div class="frame">
    <?php if (!$hasHero) { ?>
        <?php /*
            Only where there is no hero. On the home page the card runs to the
            top of the page and carries all of this in its own notches, so a bar
            here would be a second copy of the navigation above it.
        */ ?>
        <header class="topbar">
            <?php
            $brandClass = 'brand brand--topbar';
            require __DIR__ . '/brand-mark.php';
            ?>

            <?php /* The same three links the hero's notch carries, from the same
                     strings: short on screen, full as the accessible name. */ ?>
            <nav class="topbar__nav" aria-label="<?= e(Lang::get('nav.main')) ?>">
                <?php foreach ($nav as $key => $href) { ?>
                    <?php $full = Lang::get('nav.' . $key . '.full'); ?>
                    <a href="<?= e($href) ?>"<?= $isCurrent($href) ? ' aria-current="page"' : '' ?>
                       aria-label="<?= e($full) ?>" title="<?= e($full) ?>"><?=
                        e(Lang::get('nav.' . $key . '.short')) ?></a>
                <?php } ?>
            </nav>

            <a class="topbar__account" href="<?= App\Support\Shop::customerId() === null ? '/account/login.php' : '/account/' ?>">
                <?= e(Lang::get(App\Support\Shop::customerId() === null ? 'nav.signin' : 'nav.account')) ?>
            </a>

            <a class="topbar__cart" href="/cart.php">
                <?= e(Lang::get('nav.cart')) ?>
                <span class="topbar__cart-count"
                      aria-label="<?= e(Lang::get('nav.cart.count', ['count' => (int) $cartCount])) ?>"><?=
                    (int) $cartCount ?></span>
            </a>
        </header>
    <?php } ?>

    <?php $body(); ?>

    <?php if ($heroScript) { ?>
        <script type="module" src="/assets/js/hero.js?v=<?= e(ASSETS) ?>"></script>
    <?php } ?>

    <?php if ($hasHero) { ?>
        <script type="module" src="/assets/js/hero-notches.js?v=<?= e(ASSETS) ?>"></script>
    <?php } ?>

    <?php /*
        One footer, in one file, on every page.

        It used to be written out here: a wordmark and the Sketchfab credit, and
        nothing a customer could use. The four columns are now src/View/footer.php
        so a link added to it is added everywhere at once -- and the model credit
        moved into it rather than being dropped, because it is a licence condition
        and not decoration.

        $year is passed rather than called inside the partial only so the value is
        computed once on a page that renders nothing else from the clock.
    */ ?>
    <?php
    $year = (int) date('Y');
    require __DIR__ . '/footer.php';
    ?>
</div>

</body>
</html>
