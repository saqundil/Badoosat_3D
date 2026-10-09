<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\GarmentService;
use App\Service\ProductService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Lang;
use App\Support\Shop;

Csrf::session();

/*
 * The catalogue, in its two halves.
 *
 * There is no page that lists everything, by decision: the shop sells one
 * garment in two cuts, and a grid mixing them answers a question nobody asked
 * while hiding the one distinction that matters -- a printed graphic cut and a
 * plain basic cut hang differently, fit differently and are bought for different
 * reasons. So ?type is not a filter over a larger list. It is which of the two
 * catalogues this is, and it always has a value.
 *
 * WHY THIS REPLACED catalog.php
 *
 * catalog.php listed the whole catalogue and ignored ?type entirely -- while the
 * navbar and the hero's notch had been linking to /catalog.php?type=plain and
 * ?type=printed all along, so both links landed on the same undifferentiated
 * grid. catalog.php now redirects here, keeping the query string, and the links
 * resolve to the page they always claimed to open.
 *
 * WHAT THE TOOLBAR IS, AND WHY IT IS NOT A FACET PANEL
 *
 * A search box, a colour, a size and a sort, in one row above the grid. Not a
 * sidebar: a facet panel over a cut of a dozen products is furniture, and on a
 * phone it is a panel that has to be opened before the shop can be seen.
 *
 * The colour and size controls offer only what this cut can actually be bought
 * in (ProductService::refinementsFor reads them from the live variants). That is
 * the whole design: a control that can produce an empty result wastes a tap, and
 * one built from the shop's full palette would do it constantly.
 *
 * It is a <form method="get"> with a real submit button, so it works with
 * JavaScript switched off. The onchange that auto-submits is an enhancement on
 * top, and the button stays in the markup rather than being hidden by it.
 */

$types = (array) Config::get('shop.catalog_types', []);

/*
 * An unknown ?type is the printed cut, not a 404. The value is looked up in the
 * config map rather than matched against a literal, so it cannot be anything the
 * shop has not declared -- which is also what keeps it safe to hand to SQL as a
 * garment key.
 */
$type = (string) ($_GET['type'] ?? '');
$type = isset($types[$type]) ? $type : (string) array_key_first($types);
$garmentKey = (string) ($types[$type] ?? '');

$sort     = (string) ($_GET['sort'] ?? 'newest');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$colorId  = (int) ($_GET['color'] ?? 0);
$sizeCode = trim((string) ($_GET['size'] ?? ''));
$search   = trim((string) ($_GET['q'] ?? ''));

$catalog = ProductService::make();
$cut     = GarmentService::make()->byKey($garmentKey);
$refine  = $catalog->refinementsFor($garmentKey);

/*
 * A colour or size in the URL that this cut does not come in is dropped rather
 * than passed to the query. Not for safety -- both are bound parameters -- but
 * because the controls below would then show "any" while the grid was narrowed by
 * something invisible, which reads as products having vanished.
 *
 * The search term is NOT dropped this way, and must not be: a term that matches
 * nothing is a real answer to a real question, and the empty state below says so
 * by quoting it back.
 */
$colorIds  = array_map('intval', array_column($refine['colors'], 'id'));
$sizeCodes = array_column($refine['sizes'], 'code');

$colorId  = in_array($colorId, $colorIds, true) ? $colorId : 0;
$sizeCode = in_array($sizeCode, $sizeCodes, true) ? $sizeCode : '';

$results = $catalog->typeCatalog(
    $garmentKey,
    $sort,
    $colorId > 0 ? $colorId : null,
    $sizeCode !== '' ? $sizeCode : null,
    $page,
    12,
    $search
);

// What the service actually searched for, after trimming and truncation, so the
// box and the "no results for X" line quote the same string the query used.
$search = $results['search'];

/* The cut's own Arabic name when the row is there; nothing when it is not -- a
   database missing migration 008 should still render a page with a title. */
$heading = Lang::get($type === 'printed' ? 'products.printed' : 'products.plain');
$cutName = (string) ($cut['name_ar'] ?? '');

$sorts = [];

foreach (['newest', 'bestselling', 'price_asc', 'price_desc'] as $key) {
    $sorts[$key] = Lang::get('products.sort.' . $key);
}

$tabs = [
    'printed' => Lang::get('products.tab.printed'),
    'plain'   => Lang::get('products.tab.plain'),
];

/**
 * A URL for this page with some parameters changed and the rest kept.
 *
 * Every link on the page goes through it, which is what stops a sort link from
 * silently dropping the colour or the search term the shopper chose -- the bug
 * this shape of helper exists to prevent. Passing null removes a parameter;
 * changing anything but the page number resets to page 1, because page 4 of a
 * narrower result is usually past the end of it.
 */
$link = static function (array $overrides) use ($type, $sort, $colorId, $sizeCode, $search, $results): string {
    $query = [
        'type'  => $type,
        'q'     => $search !== '' ? $search : null,
        'sort'  => $sort,
        'color' => $colorId > 0 ? $colorId : null,
        'size'  => $sizeCode !== '' ? $sizeCode : null,
        'page'  => $results['page'] > 1 ? $results['page'] : null,
        ...$overrides,
    ];

    if (!array_key_exists('page', $overrides)) {
        $query['page'] = null;
    }

    // 'newest' is the default, so it is left out: a canonical URL for the
    // unsorted view, rather than two URLs for one page.
    if (($query['sort'] ?? null) === 'newest') {
        $query['sort'] = null;
    }

    $query = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null);

    return '/products.php?' . http_build_query($query);
};

$isNarrowed = $colorId > 0 || $sizeCode !== '';
$isFiltered = $isNarrowed || $search !== '';

$title     = $heading . ' — ' . Config::get('app.name');
$current   = $type;
$cartCount = Shop::cartCount();
$styles    = ['products.css'];

$body = static function () use (
    $heading,
    $cutName,
    $type,
    $tabs,
    $sorts,
    $sort,
    $refine,
    $colorId,
    $sizeCode,
    $search,
    $results,
    $link,
    $isNarrowed,
    $isFiltered
): void {
    ?>
    <?php /*
        class="page--catalog" is not decoration and not a leftover. The product
        card's entire stylesheet is scoped to it -- the tile's radius, the price
        pill, the notch cut between them -- and /assets/js/catalog-cards.js writes
        the lengths those rules read. Remove it and the cards lose their shape.

        There is no dir on this element any more. It carried dir="rtl" while the
        document was English and this page was an Arabic island inside it; <html>
        is Arabic and right-to-left now, so the card, the price pill and the notch
        cut for it all read the same direction from the same place.
    */ ?>
    <main class="page page--catalog products-page">
        <header class="products-head">
            <nav class="crumbs" aria-label="<?= e(Lang::get('products.crumbs')) ?>">
                <ol>
                    <li><a href="/"><?= e(Lang::get('products.home')) ?></a></li>
                    <li aria-current="page"><?= e($heading) ?></li>
                </ol>
            </nav>

            <h1 class="products-head__title"><?= e($heading) ?></h1>

            <p class="products-head__meta">
                <?php /*
                    The count is the size of the whole result, not of this page --
                    "12 products" over a page of four would be a lie -- and it says
                    so when the result has been narrowed or searched.
                */ ?>
                <?= e(Lang::get(
                    $isFiltered ? 'products.count.matched' : 'products.count',
                    ['count' => (int) $results['total']]
                )) ?>
                <?php if ($cutName !== '') { ?>
                    <span class="products-head__cut">· <?= e($cutName) ?></span>
                <?php } ?>
            </p>
        </header>

        <?php /*
            Two links marked up as tabs over the grid, which is what carries the
            selected state to a screen reader -- and it is the same state the
            stylesheet paints from [aria-selected="true"], so the two cannot
            disagree. They are real links and not buttons because each one is a
            different URL that must be shareable and bookmarkable.
        */ ?>
        <nav class="cut-tabs" role="tablist" aria-label="<?= e(Lang::get('products.tabs')) ?>">
            <?php foreach ($tabs as $key => $label) { ?>
                <a class="cut-tabs__tab"
                   href="/products.php?type=<?= e($key) ?>"
                   role="tab"
                   aria-controls="products-results"
                   aria-selected="<?= $type === $key ? 'true' : 'false' ?>"><?= e($label) ?></a>
            <?php } ?>
        </nav>

        <?php /*
            GET, and a real submit button. Every value the page is showing is in
            the URL, so this row is also what makes the back button behave: a
            shopper who searched, narrowed to black in L and pressed back gets the
            previous state, not the unfiltered grid.

            No hidden page field: changing a narrowing goes back to page 1, and
            leaving it out is how that happens with no code.
        */ ?>
        <form class="refine" method="get" action="/products.php" role="search">
            <input type="hidden" name="type" value="<?= e($type) ?>">

            <?php /*
                type="search", so a phone offers a search keyboard with a "go" key
                and the browser draws its own clear affordance. The magnifier is
                decorative -- the field has a real label beside it -- so it is
                aria-hidden rather than given a name of its own.
            */ ?>
            <label class="refine__field refine__field--search">
                <span class="refine__label"><?= e(Lang::get('products.search')) ?></span>
                <span class="refine__search">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"></circle>
                        <path d="M20 20l-3.2-3.2" fill="none" stroke="currentColor"
                              stroke-width="2" stroke-linecap="round"></path>
                    </svg>
                    <input type="search" name="q" value="<?= e($search) ?>" maxlength="80"
                           autocomplete="off"
                           placeholder="<?= e(Lang::get('products.search')) ?>">
                </span>
            </label>

            <?php if ($refine['colors'] !== []) { ?>
                <label class="refine__field">
                    <span class="refine__label"><?= e(Lang::get('products.color')) ?></span>
                    <select name="color" onchange="this.form.requestSubmit()">
                        <option value=""><?= e(Lang::get('products.color.all')) ?></option>
                        <?php foreach ($refine['colors'] as $color) { ?>
                            <option value="<?= (int) $color['id'] ?>"
                                <?= $colorId === (int) $color['id'] ? ' selected' : '' ?>><?= e($color['name']) ?></option>
                        <?php } ?>
                    </select>
                </label>
            <?php } ?>

            <?php if ($refine['sizes'] !== []) { ?>
                <label class="refine__field">
                    <span class="refine__label"><?= e(Lang::get('products.size')) ?></span>
                    <select name="size" onchange="this.form.requestSubmit()">
                        <option value=""><?= e(Lang::get('products.size.all')) ?></option>
                        <?php foreach ($refine['sizes'] as $size) { ?>
                            <option value="<?= e($size['code']) ?>"
                                <?= $sizeCode === (string) $size['code'] ? ' selected' : '' ?>><?=
                                e($size['label']) ?></option>
                        <?php } ?>
                    </select>
                </label>
            <?php } ?>

            <label class="refine__field">
                <span class="refine__label"><?= e(Lang::get('products.sort')) ?></span>
                <select name="sort" onchange="this.form.requestSubmit()">
                    <?php foreach ($sorts as $key => $label) { ?>
                        <option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php } ?>
                </select>
            </label>

            <?php /*
                Visible, not hidden. With scripting off this is the only way to
                apply the row -- and it is the button a search field needs anyway,
                because a select that auto-submits beside a text box that does not
                would otherwise behave two different ways in one row.
            */ ?>
            <button type="submit" class="btn btn--outline btn--sm refine__go"><?=
                e(Lang::get('products.apply')) ?></button>

            <?php if ($isFiltered) { ?>
                <a class="refine__clear" href="<?= e($link(['color' => null, 'size' => null, 'q' => null])) ?>"><?=
                    e(Lang::get($search !== '' && !$isNarrowed ? 'products.search.clear' : 'products.clear')) ?></a>
            <?php } ?>
        </form>

        <div id="products-results" role="tabpanel" aria-live="polite">
            <?php if ($results['items'] === []) { ?>
                <?php /*
                    A designed empty state with a way out of it, and three of them,
                    because there are three different reasons to be here. Searched
                    and found nothing: quote the term back and offer to clear it.
                    Narrowed to nothing: offer to widen. An empty cut: offer the
                    other cut and the editor -- because a shopper standing at an
                    empty shelf still wants a shirt.
                */ ?>
                <div class="empty-state">
                    <svg class="empty-state__mark" viewBox="0 0 48 48" width="48" height="48"
                         fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M17 7l-8 4 3 8 4-1.5V41h16V17.5L36 19l3-8-8-4a7 7 0 0 1-14 0Z"/>
                    </svg>

                    <?php if ($search !== '') { ?>
                        <h2 class="empty-state__title"><?=
                            e(Lang::get('products.empty.search.title', ['term' => $search])) ?></h2>
                        <p class="empty-state__text"><?= e(Lang::get('products.empty.search.text')) ?></p>
                        <a class="btn btn--primary btn--md" href="<?= e($link(['q' => null])) ?>"><?=
                            e(Lang::get('products.empty.search.cta')) ?></a>
                    <?php } elseif ($isNarrowed) { ?>
                        <h2 class="empty-state__title"><?= e(Lang::get('products.empty.narrow.title')) ?></h2>
                        <p class="empty-state__text"><?= e(Lang::get('products.empty.narrow.text')) ?></p>
                        <a class="btn btn--primary btn--md"
                           href="<?= e($link(['color' => null, 'size' => null])) ?>"><?=
                            e(Lang::get('products.empty.narrow.cta')) ?></a>
                    <?php } else { ?>
                        <h2 class="empty-state__title"><?= e(Lang::get('products.empty.none.title')) ?></h2>
                        <p class="empty-state__text"><?= e(Lang::get('products.empty.none.text')) ?></p>
                        <div class="empty-state__actions">
                            <a class="btn btn--primary btn--md"
                               href="/products.php?type=<?= $type === 'printed' ? 'plain' : 'printed' ?>"><?=
                                e(Lang::get($type === 'printed'
                                    ? 'products.empty.none.plain'
                                    : 'products.empty.none.printed')) ?></a>
                            <a class="btn btn--outline btn--md" href="/design.php"><?=
                                e(Lang::get('products.empty.none.design')) ?></a>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <div class="products-grid">
                    <?php foreach ($results['items'] as $index => $product) { ?>
                        <?php /*
                            The stagger is capped at seven steps. Uncapped, the last
                            card of a twelve-card page would wait most of a second
                            after the first, which stops reading as a flourish and
                            starts reading as a slow page.
                        */ ?>
                        <div class="products-grid__cell" data-reveal
                             style="--reveal-delay: <?= min(7, (int) $index) * 50 ?>ms">
                            <?php require dirname(__DIR__) . '/src/View/product-card.php'; ?>
                        </div>
                    <?php } ?>
                </div>

                <?php if ($results['pages'] > 1) { ?>
                    <?php
                    /*
                     * A window of pages around the current one, never the whole
                     * run: a cut with forty pages would otherwise print forty
                     * links, and on a phone that is a wall. First and last are
                     * always reachable, with an ellipsis standing in for the gap.
                     */
                    $current = $results['page'];
                    $last    = $results['pages'];
                    $window  = range(max(1, $current - 1), min($last, $current + 1));
                    $numbers = array_values(array_unique([1, ...$window, $last]));
                    sort($numbers);
                    ?>
                    <nav class="pager" aria-label="<?= e(Lang::get('products.pages')) ?>">
                        <?php if ($current > 1) { ?>
                            <a class="pager__step" href="<?= e($link(['page' => $current - 1])) ?>"
                               rel="prev"><?= e(Lang::get('products.prev')) ?></a>
                        <?php } else { ?>
                            <span class="pager__step" aria-disabled="true"><?= e(Lang::get('products.prev')) ?></span>
                        <?php } ?>

                        <ol class="pager__list">
                            <?php $previous = 0; ?>
                            <?php foreach ($numbers as $number) { ?>
                                <?php if ($previous > 0 && $number > $previous + 1) { ?>
                                    <li class="pager__gap" aria-hidden="true">…</li>
                                <?php } ?>
                                <li>
                                    <?php if ($number === $current) { ?>
                                        <span class="pager__page" aria-current="page"><?= (int) $number ?></span>
                                    <?php } else { ?>
                                        <a class="pager__page"
                                           href="<?= e($link(['page' => $number])) ?>"><?= (int) $number ?></a>
                                    <?php } ?>
                                </li>
                                <?php $previous = $number; ?>
                            <?php } ?>
                        </ol>

                        <?php if ($current < $last) { ?>
                            <a class="pager__step" href="<?= e($link(['page' => $current + 1])) ?>"
                               rel="next"><?= e(Lang::get('products.next')) ?></a>
                        <?php } else { ?>
                            <span class="pager__step" aria-disabled="true"><?= e(Lang::get('products.next')) ?></span>
                        <?php } ?>
                    </nav>
                <?php } ?>
            <?php } ?>
        </div>
    </main>

    <?php /* Cuts the notch the price pill sits in. The same module the home
             page's strip loads, and the only place those lengths are decided. */ ?>
    <script type="module" src="/assets/js/catalog-cards.js?v=<?= e(ASSETS) ?>"></script>
    <script type="module" src="/assets/js/reveal.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
