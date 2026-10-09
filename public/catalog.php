<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Support\Config;
use App\Support\Response;

/*
 * The old listing page, kept as a redirect to the two it became.
 *
 * WHY IT IS A REDIRECT AND NOT A DELETE
 *
 * Because things point here that must not be edited. The navbar in
 * src/View/layout.php and the hero's navigation notch in src/View/hero.php both
 * link to /catalog.php?type=plain and /catalog.php?type=printed -- and had been
 * doing so while this script ignored ?type completely, so both links landed on
 * one undifferentiated grid of both cuts. Those two templates are frozen, so the
 * fix is here: the query string is carried across, and the links finally resolve
 * to the page they always claimed to open.
 *
 * Plenty of other pages link here too -- the empty cart, the missing-product
 * page, the editor's "browse ready-made products" -- and so do any bookmarks and
 * any search results from before the split. None of them break.
 *
 * WHY 301 AND NOT 302
 *
 * The move is permanent: this page is not coming back as a listing. A 301 lets a
 * browser and a search engine stop asking, which is what should happen to a URL
 * that will forward for years. If it ever needs to become a page again, it is one
 * cache generation of pain -- worth it against forwarding every visitor through
 * two requests forever.
 *
 * WHAT HAPPENED TO WHAT WAS HERE
 *
 * The listing, its sort row and its card markup are in products.php, which also
 * honours ?type. The card itself moved to src/View/product-card.php so the home
 * page's trending strip renders the same one. The price format that was a closure
 * in this file is Price::badge().
 */

$types = (array) Config::get('shop.catalog_types', []);
$type  = (string) ($_GET['type'] ?? '');

/*
 * Only the parameters the new page understands are carried, and ?type only when
 * it names a cut the shop actually has. Forwarding an unknown value would hand
 * products.php something to ignore and leave a stale word in the visitor's
 * address bar; dropping it lands them on the default cut with a clean URL.
 *
 * ?q is not carried. products.php has no search box -- it lists one cut, and a
 * cut is twelve products -- so a search term would be a parameter that silently
 * did nothing. A shopper who arrives with one sees the catalogue rather than an
 * empty result they cannot explain.
 */
$query = array_filter([
    'type' => isset($types[$type]) ? $type : null,
    'sort' => (string) ($_GET['sort'] ?? '') !== '' ? (string) $_GET['sort'] : null,
], static fn (?string $v): bool => $v !== null);

http_response_code(301);
Response::redirect('/products.php' . ($query === [] ? '' : '?' . http_build_query($query)));
