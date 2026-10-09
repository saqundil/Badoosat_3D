<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Config;
use App\Support\Response;

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');

$logDirectory = (string) Config::get('app.paths.logs');

if (is_dir($logDirectory) || mkdir($logDirectory, 0775, true)) {
    ini_set('error_log', $logDirectory . '/php.log');
}

/**
 * Cache-buster for every stylesheet and script the site serves.
 *
 * .htaccess tells browsers to keep CSS and JS for a week, which is what makes
 * a second visit fast -- and what would otherwise leave a returning visitor on
 * last week's stylesheet after a deploy. Bump this on release and every asset
 * URL changes with it.
 */
const ASSETS = '31';

/*
 * THE DOCUMENT'S LANGUAGE AND DIRECTION
 *
 * There are no DOC_LANG / DOC_DIR constants any more, and the reason is worth
 * writing down. They were introduced when the Arabic sections were islands of
 * dir="rtl" inside an English left-to-right document: a template wrapping product
 * cards had to reset the region to the document's direction, because the price
 * pill is placed with inset-inline-end -- resolved against the nearest ancestor
 * that declares a direction -- while catalog-cards.js cuts the notch the pill sits
 * in from document.documentElement.dir. Disagree about the direction and the pill
 * lands on one corner of the tile and its notch on the other.
 *
 * The whole document is Arabic now, so there are no islands and nothing to reset.
 * A pair of constants beside app.locale would be a second place to state the same
 * fact, and two places that must agree eventually do not. Lang::locale() and
 * Lang::isRtl() read config/app.php, src/View/layout.php prints them on <html>,
 * and catalog-cards.js reads that attribute back. One source, three readers.
 */

/** Escapes for HTML. Templates call this on every value, without exception. */
function e(mixed $value): string
{
    return Response::e($value);
}

/** Safe inside <script type="application/json">. */
function json_island(mixed $data): string
{
    return Response::jsonForHtml($data);
}

function money(int $cents): string
{
    return number_format($cents / 100, 2) . ' د.أ';
}
