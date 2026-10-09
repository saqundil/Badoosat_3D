<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\HeroSlideService;
use App\Service\HomeSettingsService;
use App\Service\ProductService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Lang;
use App\Support\Shop;

Csrf::session();

$heroNote = null;

try {
    $payload = HeroSlideService::make()->payload();

    if ($payload['slides'] === []) {
        $heroNote = 'الجدول موجود لكن لا توجد شرائح مفعّلة. أضف شريحة من لوحة التحكم أو فعّل شريحة معطّلة.';
    }
} catch (PDOException $e) {
    // A hero without a database is still a page: the frame, the nav and the
    // credit all render. The reason is logged, and shown only in debug.
    error_log('Hero: database unavailable — ' . $e->getMessage());

    $payload  = ['slides' => [], 'garment' => Config::file('garments')['tshirt']];
    $heroNote = Config::get('app.debug')
        ? 'تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage()
        : 'تعذّر الاتصال بقاعدة البيانات. تحقّق من إعدادات الاتصال وتأكّد أن MySQL يعمل.';
}

$slides  = $payload['slides'];
$garment = $payload['garment'];

/*
 * The English overlay that used to sit here is gone.
 *
 * It mapped each Arabic slide field coming out of the database to an English
 * string, in a literal array, so an English-language shop could run off Arabic
 * data. That was a workaround for having no translation layer; there is one now
 * (lang/ar.php, and lang/en.php when someone writes it), and the slides are the
 * shop owner's own words either way -- they are edited in the admin panel, not
 * translated in a template. A second language for slide CONTENT is a column on
 * hero_slides, not a lookup table in the page that renders them.
 */

/*
 * What the sections below the hero need, fetched here rather than inside the
 * templates so a template cannot open a connection while it renders.
 *
 * Both calls are wrapped, and for the same reason the hero above is: the landing
 * page is the one page that has to render when the database does not answer. The
 * settings service already swallows its own PDOException and returns the
 * defaults, so the catch here is really about the trending strip, whose query
 * reads products.is_trending -- a column that does not exist between deploying
 * this code and running migration 010. The page then shows no strip, which is the
 * same thing it shows when nothing has been marked trending.
 */
$settings = HomeSettingsService::make()->settings();
$trending = [];

try {
    $trending = ProductService::make()->trending(8);
} catch (PDOException $e) {
    error_log('Trending strip unavailable — ' . $e->getMessage());
}

$title     = Config::get('app.name');
$current   = '';
$poster    = $slides[0]['poster_path'] ?? null;
$importmap = true;
$cartCount = Shop::cartCount();
$styles    = ['home.css'];

$body = static function () use ($slides, $garment, $heroNote, $cartCount, $settings, $trending): void {
    $auto  = true;
    $label = Lang::get('hero.featured');
    // The card carries the site navigation in its notches, the cart badge with it.
    require dirname(__DIR__) . '/src/View/hero.php';

    /*
     * Everything from here down is new, and nothing above this line was touched:
     * the hero is required exactly as it was, with the same three variables.
     *
     * The order is the reading order of the page. Two doors into the catalogue
     * first, because a visitor who has just met the hero wants to know what is
     * for sale. Then the products themselves. Then the invitation to design one
     * -- after they have seen what the printing looks like, not before, which is
     * the same argument the catalogue's own invitation card makes for sitting
     * third in the grid rather than first.
     */
    require dirname(__DIR__) . '/src/View/home-categories.php';

    // No strip when the owner has marked nothing: a section heading over an empty
    // row is worse than no section.
    if ($trending !== []) {
        require dirname(__DIR__) . '/src/View/home-trending.php';
    }

    require dirname(__DIR__) . '/src/View/home-promo.php';
    ?>
    <section class="strip" id="about">
        <h2 class="strip__title"><?= e(Lang::get('home.about.title')) ?></h2>
        <div class="strip__grid">
            <?php for ($i = 1; $i <= 3; $i++) { ?>
                <article>
                    <h3><?= e(Lang::get('home.about.' . $i . '.title')) ?></h3>
                    <p><?= e(Lang::get('home.about.' . $i . '.text')) ?></p>
                </article>
            <?php } ?>
        </div>
    </section>

    <?php /*
        The notch under each trending card's price. Same module the catalogue
        loads, and it has to be this one: it is what writes --R, --badge-w,
        --badge-h and the clip-path onto the elements the stylesheet reads them
        back from. Loaded only when there are cards to measure.
    */ ?>
    <?php if ($trending !== []) { ?>
        <script type="module" src="/assets/js/catalog-cards.js?v=<?= e(ASSETS) ?>"></script>
    <?php } ?>

    <script type="module" src="/assets/js/reveal.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
