<?php

declare(strict_types=1);

/**
 * The dashboard's top bar and the tabs of the section in use.
 *
 * FIVE GROUPS, PAGES AS TABS
 *
 * The bar holds the groups -- Overview · Orders · Catalog · Storefront ·
 * System -- and a group's pages are tabs in a row under it, never more items
 * in the bar. A group or a tab whose page does not exist yet is left out
 * rather than shown as a link to nowhere: Overview and Designs appear on their
 * own once their pages are built.
 *
 * NARROWING
 *
 * Labels show while they fit; from 768px to 1023px the groups are icons with
 * their names as tooltips (and accessible names); below 768px the bar folds
 * into a menu button. Without script the groups stay a row that scrolls
 * inside itself.
 *
 * The class names are the bar's own (admin-bar, admin-nav): the storefront's
 * hero.css, which the dashboard loads for its fonts, styles .topbar and
 * .topbar__nav, and those rules must not reach here.
 *
 * @var string $current  key of the page in use (see $tabOf below)
 */

use App\Support\Csrf;
use App\Support\Lang;

$current ??= '';

$root = dirname(__DIR__, 3) . '/public/admin/';

/** group => icon and tabs (tab key => [href, file that must exist]). */
$groups = [
    'overview'   => ['icon' => 'grid',  'tabs' => ['overview' => ['/admin/overview.php', 'overview.php']]],
    'orders'     => ['icon' => 'inbox', 'tabs' => ['orders' => ['/admin/orders.php', 'orders.php']]],
    'catalog'    => ['icon' => 'shirt', 'tabs' => [
        'products' => ['/admin/products.php', 'products.php'],
        'garments' => ['/admin/garments.php', 'garments.php'],
        'stock'    => ['/admin/catalog.php', 'catalog.php'],
        'designs'  => ['/admin/designs.php', 'designs.php'],
    ]],
    'storefront' => ['icon' => 'store', 'tabs' => [
        'slides' => ['/admin/', 'index.php'],
        'home'   => ['/admin/home.php', 'home.php'],
    ]],
    'system'     => ['icon' => 'gear',  'tabs' => ['system' => ['/admin/system.php', 'system.php']]],
];

// The pages still name themselves by their old keys; this maps them to tabs.
$tabOf = ['catalog' => 'stock'];
$activeTab = $tabOf[$current] ?? $current;
$activeGroup = null;

foreach ($groups as $key => &$group) {
    $group['tabs'] = array_filter($group['tabs'], static fn (array $tab): bool => is_file($root . $tab[1]));
    if (isset($group['tabs'][$activeTab])) {
        $activeGroup = $key;
    }
}
unset($group);
$groups = array_filter($groups, static fn (array $group): bool => $group['tabs'] !== []);

$admin   = (string) ($_SESSION['admin'] ?? '');
$locales = Lang::adminLocales();
?>
<header class="admin-bar" data-admin-bar>
    <a class="admin-bar__brand" href="/admin/">
        <?php
        $brandClass  = 'brand';
        $brandHeight = 24;
        $brandLink   = false;
        require dirname(__DIR__) . '/brand-mark.php';
        ?>
        <span class="visually-hidden"><?= e(Lang::get('admin.title')) ?></span>
    </a>

    <button type="button" class="icon-button admin-bar__menu" data-nav-toggle aria-controls="admin-nav" aria-expanded="false"
            aria-label="<?= e(Lang::get('admin.nav.menu')) ?>"><?= admin_icon('menu') ?></button>

    <nav class="admin-nav" id="admin-nav" aria-label="<?= e(Lang::get('admin.nav.label')) ?>">
        <ul class="admin-nav__groups">
            <?php foreach ($groups as $key => $group) {
                $label = Lang::get('admin.nav.group.' . $key);
                $href  = reset($group['tabs'])[0]; ?>
                <li>
                    <a class="admin-nav__link" href="<?= e($href) ?>" title="<?= e($label) ?>"<?= $key === $activeGroup ? ' aria-current="page"' : '' ?>>
                        <?= admin_icon($group['icon']) ?>
                        <span class="admin-nav__label"><?= e($label) ?></span>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </nav>

    <div class="admin-bar__actions">
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

<?php if ($activeGroup !== null && count($groups[$activeGroup]['tabs']) > 1) { ?>
    <nav class="admin-tabs" aria-label="<?= e(Lang::get('admin.nav.tabs_label')) ?>">
        <ul class="admin-tabs__list">
            <?php foreach ($groups[$activeGroup]['tabs'] as $key => $tab) { ?>
                <li>
                    <a class="admin-tabs__link" href="<?= e($tab[0]) ?>"<?= $key === $activeTab ? ' aria-current="page"' : '' ?>>
                        <?= e(Lang::get('admin.nav.tab.' . $key)) ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </nav>
<?php } ?>
