<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Support\Config;
use App\Support\Csrf;
use App\Support\Lang;
use App\Support\Shop;

Csrf::session();

/*
 * The four things the footer's customer-service column links to, on one page.
 *
 * WHY ONE PAGE AND NOT FOUR
 *
 * Because there is one paragraph to say about each of them. Four routes, four
 * templates and four titles for four paragraphs is furniture; one page with four
 * headings, addressed as #shipping, #returns, #faq and #contact, is the same
 * information with a quarter of the surface. It splits into four pages the day any
 * of them grows past a screen.
 *
 * WHY THE NUMBERS ARE NOT WRITTEN HERE
 *
 * Shipping costs, the free-shipping threshold, the quantity discount and the
 * returns notice are all read from config/shop.php -- the same file the cart and
 * the order total read them from. A shipping page that states a price the
 * checkout then contradicts is worse than no page, and that is exactly what a
 * hardcoded "٣ دينار" here would become the first time the owner changed the
 * config.
 */

$flat      = (int) Config::get('shop.shipping.flat_cents', 0);
$freeOver  = (int) Config::get('shop.shipping.free_over_cents', 0);
$returns   = (string) Config::get('shop.returns_notice', '');
$discounts = (array) Config::get('shop.quantity_discount', []);
$surcharge = (int) Config::get('shop.custom_surcharge_cents', 0);

$contact  = (array) Config::get('shop.contact', []);
/*
 * The shop's public address, and only a real one.
 *
 * It falls back to the address order confirmations are sent from, because that
 * is the one contact detail a working shop cannot be without -- but NOT when
 * that is still the shipped placeholder. config/mail.php defaults
 * mail.from.address to orders@example.com so a developer machine has something
 * to put in a From header; printing it here would hand every customer an
 * address that bounces, in English, on an Arabic page. Empty instead, and the
 * row simply does not appear.
 */
$email = trim((string) ($contact['email'] ?? ''));

if ($email === '') {
    $from  = trim((string) Config::get('mail.from.address', ''));
    $email = str_ends_with($from, '@example.com') ? '' : $from;
}
$whatsapp = (string) preg_replace('/\D+/', '', (string) ($contact['whatsapp'] ?? ''));

/*
 * Four questions, from the language file rather than from here. The answers are
 * facts about how the shop works -- the print method, the print area, the lead
 * time on a custom piece -- so they are wording, not data: a fifth question is a
 * pair of keys in lang/ar.php and one more number in this loop.
 *
 * The two that follow them in the markup are conditional on config, so they are
 * assembled where the numbers are read.
 */
$faq = [];

for ($i = 1; $i <= 4; $i++) {
    $faq[] = [
        'q' => Lang::get('help.faq.' . $i . '.q'),
        'a' => Lang::get('help.faq.' . $i . '.a'),
    ];
}

$title     = Lang::get('help.title') . ' — ' . Config::get('app.name');
$current   = '';
$cartCount = Shop::cartCount();
$styles    = ['products.css'];

$body = static function () use ($flat, $freeOver, $returns, $discounts, $surcharge, $email, $whatsapp, $faq): void {
    ?>
    <main class="page help-page">
        <header class="products-head">
            <nav class="crumbs" aria-label="<?= e(Lang::get('products.crumbs')) ?>">
                <ol>
                    <li><a href="/"><?= e(Lang::get('products.home')) ?></a></li>
                    <li aria-current="page"><?= e(Lang::get('help.title')) ?></li>
                </ol>
            </nav>

            <h1 class="products-head__title"><?= e(Lang::get('help.title')) ?></h1>
            <p class="products-head__meta"><?= e(Lang::get('help.lede')) ?></p>
        </header>

        <?php /*
            scroll-margin-top on these headings is in products.css. Without it an
            anchor from the footer scrolls the heading flush to the top edge of
            the viewport, where it reads as the page having started mid-sentence.
        */ ?>
        <section class="help-block" id="shipping" data-reveal>
            <h2 class="help-block__title"><?= e(Lang::get('help.shipping.title')) ?></h2>
            <p>
                <?php /*
                    The amount is put INTO the sentence rather than concatenated
                    onto it: the word order around a number is not the same in
                    every language, so the string carries :amount and the markup is
                    substituted in. e() is applied to the sentence first and to the
                    amount separately, so both are escaped and the <strong> that
                    joins them is the only markup that survives.
                */ ?>
                <?= str_replace(
                    ':amount',
                    '<strong>' . e(money($flat)) . '</strong>',
                    e(Lang::get('help.shipping.flat'))
                ) ?>
                <?php if ($freeOver > 0) { ?>
                    <?= str_replace(
                        ':amount',
                        '<strong>' . e(money($freeOver)) . '</strong>',
                        e(Lang::get('help.shipping.free'))
                    ) ?>
                <?php } ?>
            </p>
            <p>
                <?= e(Lang::get('help.shipping.text')) ?>
                <?= str_replace(
                    ':link',
                    '<a href="/track.php">' . e(Lang::get('help.shipping.track')) . '</a>',
                    e(Lang::get('help.shipping.after'))
                ) ?>
            </p>
        </section>

        <section class="help-block" id="returns" data-reveal>
            <h2 class="help-block__title"><?= e(Lang::get('help.returns.title')) ?></h2>
            <?php if ($returns !== '') { ?>
                <?php /* The same sentence the checkout shows before payment, from
                         the same config key, so the two cannot disagree. */ ?>
                <p class="help-block__notice"><?= e($returns) ?></p>
            <?php } ?>
            <p><?= e(Lang::get('help.returns.ready')) ?></p>
            <p><?= e(Lang::get('help.returns.custom')) ?></p>
        </section>

        <section class="help-block" id="faq" data-reveal>
            <h2 class="help-block__title"><?= e(Lang::get('help.faq.title')) ?></h2>

            <?php /*
                <details> and not a JavaScript accordion. It opens with scripting
                off, it is searchable by the browser's own find-in-page in current
                browsers, and it is keyboard-operable without a single line of
                code. There is nothing an accordion widget would add here.
            */ ?>
            <div class="faq">
                <?php foreach ($faq as $item) { ?>
                    <details class="faq__item">
                        <summary class="faq__q"><?= e($item['q']) ?></summary>
                        <p class="faq__a"><?= e($item['a']) ?></p>
                    </details>
                <?php } ?>

                <?php if ($discounts !== []) { ?>
                    <details class="faq__item">
                        <summary class="faq__q"><?= e(Lang::get('help.faq.qty.q')) ?></summary>
                        <p class="faq__a">
                            <?php
                            $tiers = [];

                            foreach ($discounts as $from => $percent) {
                                $tiers[] = Lang::get('help.faq.qty.tier', [
                                    'from'    => (int) $from,
                                    'percent' => (int) $percent,
                                ]);
                            }
                            ?>
                            <?= e(Lang::get('help.faq.qty.a', ['tiers' => implode(' · ', $tiers)])) ?>
                        </p>
                    </details>
                <?php } ?>

                <?php if ($surcharge > 0) { ?>
                    <details class="faq__item">
                        <summary class="faq__q"><?= e(Lang::get('help.faq.custom.q')) ?></summary>
                        <p class="faq__a">
                            <?= str_replace(
                                ':amount',
                                '<strong>' . e(money($surcharge)) . '</strong>',
                                e(Lang::get('help.faq.custom.a'))
                            ) ?>
                        </p>
                    </details>
                <?php } ?>
            </div>
        </section>

        <section class="help-block" id="contact" data-reveal>
            <h2 class="help-block__title"><?= e(Lang::get('help.contact.title')) ?></h2>
            <p>
                <?php /*
                    No contact form. A form needs somewhere for the message to go
                    -- a table, a mailbox, a queue -- and the shop's mail driver is
                    'log' until someone configures SMTP, so a form here would
                    silently swallow every enquiry it received. WhatsApp and email
                    are channels that demonstrably work.
                */ ?>
                <?= e(Lang::get('help.contact.text')) ?>
            </p>

            <ul class="help-contact">
                <?php if ($whatsapp !== '') { ?>
                    <li>
                        <a class="btn btn--primary btn--md" href="https://wa.me/<?= e($whatsapp) ?>"
                           rel="noopener" target="_blank"><?= e(Lang::get('help.contact.whatsapp')) ?></a>
                    </li>
                <?php } ?>
                <?php if ($email !== '') { ?>
                    <li>
                        <?php /* Latin script inside an Arabic page, so it declares
                                 its own direction: without it the bidi algorithm
                                 moves a trailing dot to the wrong end. */ ?>
                        <a class="btn btn--outline btn--md" href="mailto:<?= e($email) ?>">
                            <span dir="ltr"><?= e($email) ?></span>
                        </a>
                    </li>
                <?php } ?>
                <li><a class="btn btn--outline btn--md" href="/track.php"><?=
                    e(Lang::get('help.contact.track')) ?></a></li>
            </ul>

            <?php if ($whatsapp === '') { ?>
                <?php /*
                    Said out loud rather than rendered as a dead button. The owner
                    fills shop.contact.whatsapp in config/local.php and the button
                    above appears; until then the page does not pretend to offer a
                    channel nobody is listening on.
                */ ?>
                <p class="help-block__notice"><?= e(Lang::get('help.contact.missing')) ?></p>
            <?php } ?>
        </section>
    </main>

    <script type="module" src="/assets/js/reveal.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__) . '/src/View/layout.php';
