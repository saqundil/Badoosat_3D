<?php

declare(strict_types=1);

/**
 * The dashboard's top bar: logo, the dark pill of sections, the language
 * switch and the circle actions.
 *
 * Under 768px the section pill becomes a row that scrolls sideways inside
 * itself -- the page never does.
 *
 * @var string $current  key of the active section
 */

use App\Support\Csrf;
use App\Support\Lang;

$current ??= '';

$sections = [
    'slides'   => '/admin/',
    // Types come before products because a product cannot exist without one:
    // it decides the model, the colours and the sizes the product may use.
    'garments' => '/admin/garments.php',
    'products' => '/admin/products.php',
    'catalog'  => '/admin/catalog.php',
    // The landing page's own screen: its two category photographs, the banner
    // over the design editor, and which products the trending strip shows.
    'home'     => '/admin/home.php',
    'orders'   => '/admin/orders.php',
    'system'   => '/admin/system.php',
];

$admin   = (string) ($_SESSION['admin'] ?? '');
$locales = Lang::adminLocales();
?>
<header class="topbar">
    <a class="topbar__brand" href="/admin/">
        <?php
        $brandClass  = 'brand';
        $brandHeight = 24;
        $brandLink   = false;
        require dirname(__DIR__) . '/brand-mark.php';
        ?>
        <span class="visually-hidden"><?= e(Lang::get('admin.title')) ?></span>
    </a>

    <nav class="topbar__nav" aria-label="<?= e(Lang::get('admin.nav.label')) ?>">
        <ul class="topbar__sections">
            <?php foreach ($sections as $key => $href) { ?>
                <li>
                    <a class="topbar__link" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>>
                        <?= e(Lang::get('admin.nav.' . $key)) ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </nav>

    <div class="topbar__actions">
        <?php if (count($locales) > 1) { ?>
            <ul class="lang-switch" aria-label="<?= e(Lang::get('admin.lang.label')) ?>">
                <?php foreach ($locales as $code) { ?>
                    <li>
                        <?php if ($code === Lang::locale()) { ?>
                            <span class="lang-switch__item" aria-current="true" lang="<?= e($code) ?>">
                                <?= e(Lang::nativeName($code)) ?>
                            </span>
                        <?php } else { ?>
                            <a class="lang-switch__item" href="<?= e(admin_url(['lang' => $code])) ?>"
                               lang="<?= e($code) ?>" hreflang="<?= e($code) ?>">
                                <?= e(Lang::nativeName($code)) ?>
                            </a>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>

        <a class="icon-button" href="/" target="_blank" rel="noopener"
           aria-label="<?= e(Lang::get('admin.nav.view_site')) ?>" title="<?= e(Lang::get('admin.nav.view_site')) ?>">
            <?= admin_icon('external') ?>
        </a>

        <form method="post" action="/admin/logout.php" class="inline">
            <?= Csrf::field() ?>
            <button type="submit" class="icon-button"
                    aria-label="<?= e(Lang::get('admin.nav.logout')) ?>" title="<?= e(Lang::get('admin.nav.logout')) ?>">
                <?= admin_icon('logout') ?>
            </button>
        </form>

        <?php if ($admin !== '') { ?>
            <span class="avatar avatar--accent" title="<?= e($admin) ?>">
                <bdi><?= e(admin_initials($admin)) ?></bdi>
            </span>
        <?php } ?>
    </div>
</header>
