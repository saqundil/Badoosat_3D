<?php

declare(strict_types=1);

/**
 * Everything about money that an owner might reasonably want to change without
 * a developer. Nothing here is ever read from the browser: the cart and the
 * order recompute from this file and the database on every single display.
 */

return [
    'currency' => 'د.أ',

    // Prefix + five characters from an alphabet with no I, O, 0 or 1 in it, so
    // an order number can be read down a phone line without spelling it out.
    'order_number' => [
        'prefix'   => 'TS-',
        'alphabet' => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ',
        'length'   => 5,
    ],

    'shipping' => [
        'flat_cents'     => 300,
        // Free above this subtotal, after any quantity discount.
        'free_over_cents' => 5000,
    ],

    /*
     * Quantity discount, applied per line, on the printed price. A team buying
     * ten shirts is one conversation and one print run, so it costs less to
     * serve than ten separate orders and should be priced that way.
     *
     * Read as: from this many pieces on a line, take this percentage off.
     */
    'quantity_discount' => [
        10 => 5,
        25 => 10,
        50 => 15,
    ],

    // What printing a customer's own artwork adds to the garment, per piece.
    // A ready-made design carries its own surcharge on the designs table.
    'custom_surcharge_cents' => 500,

    // Both sides means two transfers and two presses.
    'both_sides_multiplier' => 1.8,

    'cart' => [
        'max_lines'          => 30,
        'max_quantity_line'  => 200,
        'cookie'             => 'cart_ref',
        'cookie_days'        => 30,
    ],

    'returns_notice' => 'المنتج المخصّص غير قابل للإرجاع بعد بدء الإنتاج.',

    /*
     * How a customer reaches a human, for the footer and the help page.
     *
     * Every one of these is empty by default and every one is optional: the
     * footer renders a channel only when it has been filled in. That is the
     * point of the empty strings -- a social icon linking to the platform's
     * home page, or a WhatsApp button that opens a chat with nobody, is worse
     * than an absent one, and a placeholder handle typed in here to "fill the
     * row" is a link a customer will actually click.
     *
     * whatsapp is digits only, in international form and with no + or spaces:
     * it goes straight into a wa.me URL. The socials are handles without the @,
     * and 'email' falls back to the address order confirmations are sent from,
     * which is the one contact detail the shop cannot operate without.
     */
    /*
     * The two halves of the catalogue, as the URL spells them.
     *
     * products.php?type=printed and ?type=plain are what the navbar, the hero's
     * notch and the footer all link to; the values are `garments`.`key` rows. The
     * mapping lives here rather than in the page because it is a merchandising
     * decision -- which cut is "the printed one" -- and because renaming a cut in
     * the admin screen would otherwise mean editing a template.
     *
     * The FIRST key is the default: an unknown ?type, or none at all, is served
     * as that one rather than as an error, because a bad link from outside the
     * shop should land on a catalogue and not on a 404.
     */
    'catalog_types' => [
        'printed' => 'graphic-premium-cut',
        'plain'   => 'premium-basic-cut',
    ],

    'contact' => [
        'whatsapp'  => '',
        'email'     => '',
        'instagram' => '',
        'facebook'  => '',
        'tiktok'    => '',
    ],

    // Where the catalogue page's file cache lives and how long it may be stale.
    // Any product or design save clears it outright, so the window only matters
    // if a row is edited straight in the database.
    'catalog_cache_ttl' => 900,
];
