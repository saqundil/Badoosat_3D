<?php

declare(strict_types=1);

/**
 * The site footer, on every page.
 *
 * Required from layout.php, which is what makes it "every page": there is no
 * page that renders its own, and adding a column here adds it everywhere.
 *
 * NO dir ON THIS ELEMENT ANY MORE
 *
 * It carried dir="rtl" while the document was English and left-to-right and the
 * footer was an Arabic island inside it. <html> is Arabic and right-to-left now,
 * so the attribute would be a second statement of a fact already made one level
 * up -- and the kind that gets left behind when the fact changes. The one dir
 * that remains is on the model credit, which is a sentence of English.
 *
 * WHAT IS NOT HERE
 *
 * No social icon for a network the shop has not given a handle for. Every
 * channel below is rendered only if config/shop.php has it, because an
 * Instagram link that opens instagram.com is a link a customer clicks once.
 *
 * @var int $year
 */

use App\Support\Config;
use App\Support\Lang;

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
 * Label, href, and which icon. Kept as one list so the markup below is a single
 * loop: three near-identical <a> blocks is how one of them ends up missing a
 * rel="noopener" six months from now.
 */
$socials = array_values(array_filter([
    ($contact['instagram'] ?? '') === '' ? null : [
        'name' => Lang::get('footer.social.instagram'),
        'href' => 'https://instagram.com/' . ltrim((string) $contact['instagram'], '@'),
        'icon' => 'instagram',
    ],
    ($contact['facebook'] ?? '') === '' ? null : [
        'name' => Lang::get('footer.social.facebook'),
        'href' => 'https://facebook.com/' . ltrim((string) $contact['facebook'], '@'),
        'icon' => 'facebook',
    ],
    ($contact['tiktok'] ?? '') === '' ? null : [
        'name' => Lang::get('footer.social.tiktok'),
        'href' => 'https://tiktok.com/@' . ltrim((string) $contact['tiktok'], '@'),
        'icon' => 'tiktok',
    ],
]));

/*
 * Inline SVG, one path each, currentColor throughout -- so the icons inherit the
 * footer's text colour and its hover colour with no second rule, and cost no
 * request. The same 24x24 viewBox on all three so they match optically at one
 * size. No aria-hidden argument to make: the link carries the accessible name
 * and the glyph inside it is decoration.
 */
$icon = static function (string $name): string {
    return match ($name) {
        'instagram' => '<path d="M12 2.2c-2.72 0-3.06.01-4.12.06-1.07.05-1.79.22-2.43.47a4.9 4.9 0 0 0-1.77 1.15A4.9 4.9 0 0 0 2.53 5.65c-.25.64-.42 1.36-.47 2.43C2.01 9.14 2 9.48 2 12.2s.01 3.06.06 4.12c.05 1.07.22 1.79.47 2.43a4.9 4.9 0 0 0 1.15 1.77 4.9 4.9 0 0 0 1.77 1.15c.64.25 1.36.42 2.43.47 1.06.05 1.4.06 4.12.06s3.06-.01 4.12-.06c1.07-.05 1.79-.22 2.43-.47a5.1 5.1 0 0 0 2.92-2.92c.25-.64.42-1.36.47-2.43.05-1.06.06-1.4.06-4.12s-.01-3.06-.06-4.12c-.05-1.07-.22-1.79-.47-2.43a4.9 4.9 0 0 0-1.15-1.77 4.9 4.9 0 0 0-1.77-1.15c-.64-.25-1.36-.42-2.43-.47C15.26 2.21 14.92 2.2 12 2.2Zm0 1.8c2.67 0 2.99.01 4.04.06.98.04 1.5.2 1.85.34.47.18.8.4 1.15.75.35.35.57.68.75 1.15.14.35.3.87.34 1.85.05 1.05.06 1.37.06 4.04s-.01 2.99-.06 4.04c-.04.98-.2 1.5-.34 1.85-.18.47-.4.8-.75 1.15-.35.35-.68.57-1.15.75-.35.14-.87.3-1.85.34-1.05.05-1.37.06-4.04.06s-2.99-.01-4.04-.06c-.98-.04-1.5-.2-1.85-.34a3.1 3.1 0 0 1-1.15-.75 3.1 3.1 0 0 1-.75-1.15c-.14-.35-.3-.87-.34-1.85C3.82 15.19 3.8 14.87 3.8 12.2s.01-2.99.06-4.04c.04-.98.2-1.5.34-1.85.18-.47.4-.8.75-1.15.35-.35.68-.57 1.15-.75.35-.14.87-.3 1.85-.34C9 4.01 9.33 4 12 4Zm0 3.07a5.13 5.13 0 1 0 0 10.26 5.13 5.13 0 0 0 0-10.26Zm0 8.46a3.33 3.33 0 1 1 0-6.66 3.33 3.33 0 0 1 0 6.66Zm6.53-8.66a1.2 1.2 0 1 1-2.4 0 1.2 1.2 0 0 1 2.4 0Z"/>',
        'facebook'  => '<path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06C2 17.08 5.66 21.25 10.44 22v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.49-3.91 3.77-3.91 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.77l-.44 2.91h-2.33V22C18.34 21.25 22 17.08 22 12.06Z"/>',
        'tiktok'    => '<path d="M16.7 2h-3.2v13.4a2.45 2.45 0 1 1-1.75-2.35V9.72a5.72 5.72 0 1 0 4.95 5.66V8.7a5.5 5.5 0 0 0 3.3 1.12V6.6A3.4 3.4 0 0 1 16.7 3.2V2Z"/>',
        default     => '',
    };
};

/*
 * Every quick link points at a page that exists. /help.php carries the four
 * customer-service sections as anchors rather than four near-empty pages, and
 * /#about is the workshop section on the landing page.
 */
$columns = [
    Lang::get('footer.links') => [
        [Lang::get('footer.links.home'), '/'],
        [Lang::get('products.printed'), '/products.php?type=printed'],
        [Lang::get('products.plain'), '/products.php?type=plain'],
        [Lang::get('footer.links.design'), '/design.php'],
    ],
    Lang::get('footer.service') => [
        [Lang::get('footer.service.shipping'), '/help.php#shipping'],
        [Lang::get('footer.service.returns'), '/help.php#returns'],
        [Lang::get('footer.service.faq'), '/help.php#faq'],
        [Lang::get('footer.service.track'), '/track.php'],
        [Lang::get('footer.service.contact'), '/help.php#contact'],
    ],
];
?>
<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__brand">
            <?php /*
                The wordmark is a fixed 132x26 and says so on the element: the
                footer is the last thing on the page and an image without
                dimensions here shifts its whole column when it arrives.
            */ ?>
            <img class="site-footer__logo" src="/assets/img/badoosat_en_logo_name.png"
                 alt="BADOOSAT" width="132" height="26" loading="lazy" decoding="async">

            <p class="site-footer__about"><?= e(Lang::get('footer.about')) ?></p>
        </div>

        <?php foreach ($columns as $heading => $links) { ?>
            <nav class="site-footer__col" aria-label="<?= e($heading) ?>">
                <h2 class="site-footer__heading"><?= e($heading) ?></h2>
                <ul class="site-footer__links">
                    <?php foreach ($links as [$text, $href]) { ?>
                        <li><a href="<?= e($href) ?>"><?= e($text) ?></a></li>
                    <?php } ?>
                </ul>
            </nav>
        <?php } ?>

        <div class="site-footer__col">
            <h2 class="site-footer__heading"><?= e(Lang::get('footer.contact')) ?></h2>
            <ul class="site-footer__links">
                <?php if ($whatsapp !== '') { ?>
                    <li><a href="https://wa.me/<?= e($whatsapp) ?>" rel="noopener" target="_blank"><?= e(Lang::get('footer.contact.whatsapp')) ?></a></li>
                <?php } ?>
                <?php if ($email !== '') { ?>
                    <li><a href="mailto:<?= e($email) ?>" dir="ltr"><?= e($email) ?></a></li>
                <?php } ?>
                <li><a href="/help.php#contact"><?= e(Lang::get('footer.contact.form')) ?></a></li>
            </ul>

            <?php if ($socials !== []) { ?>
                <ul class="site-footer__social" aria-label="<?= e(Lang::get('footer.social')) ?>">
                    <?php foreach ($socials as $social) { ?>
                        <li>
                            <a href="<?= e($social['href']) ?>" rel="noopener" target="_blank"
                               aria-label="<?= e($social['name']) ?>" title="<?= e($social['name']) ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"
                                     aria-hidden="true" focusable="false"><?= $icon($social['icon']) ?></svg>
                            </a>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
    </div>

    <div class="site-footer__bar">
        <?php /*
            date() and not a literal. A hardcoded year is wrong for eleven of the
            twelve months after the developer stops looking at it.
        */ ?>
        <p><?= e(Lang::get('footer.rights', ['year' => $year])) ?></p>

        <?php /*
            The model credit that used to be the whole footer. It is a licence
            condition, not decoration, so it moves rather than disappears. ltr,
            because it is a sentence of English around three English links.
        */ ?>
        <p class="site-footer__credit" dir="ltr" lang="en">
            3D T-shirt model
            <a href="https://sketchfab.com/3d-models/basic-t-shirt-71bdf5940b5d41b8b46628a615e9b0ed"
               rel="noopener" target="_blank">&ldquo;Basic T-Shirt&rdquo;</a>
            by <a href="https://sketchfab.com/MadeByYeshe" rel="noopener" target="_blank">MadeByYeshe</a>,
            licensed under
            <a href="https://creativecommons.org/licenses/by/4.0/"
               rel="license noopener" target="_blank">CC BY 4.0</a>.
        </p>
    </div>
</footer>
