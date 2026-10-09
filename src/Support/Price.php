<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a price may be, and how it becomes an integer number of piastres.
 *
 * Both halves were written twice and the two copies disagreed. The product form
 * refused anything under 0.01, because a product saved at zero reaches the
 * catalogue reading as free; the catalogue's own price box accepted a negative
 * number and quietly clamped it to exactly that zero. And the conversion was
 * spelled `(int) $value * 100` in one place -- casting before multiplying, so
 * 19.99 became 1900 and the piastres were thrown away -- and
 * `(int) round((float) $value * 100)` in another.
 *
 * One rule, one conversion, read from here by everything that prices anything.
 */
final class Price
{
    /** The floor. Zero is not a price; it is a product nobody can buy. */
    public const MIN = 0.01;

    public const MAX = 1000000.0;

    /** A surcharge may legitimately be nothing at all. */
    public const SURCHARGE_MIN = 0.0;

    public const SURCHARGE_MAX = 100000.0;

    /**
     * Piastres from whatever the form sent.
     *
     * Cast to float FIRST and round after multiplying: the other order truncates
     * the decimal part before it can reach the hundreds column, which is the
     * whole of the bug this replaces. round() rather than a cast so 19.99 * 100
     * -- which is 1998.9999... in binary floating point -- lands on 1999.
     */
    public static function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * A price for the pill on a product card, and nowhere else.
     *
     * money() prints piastres, which is right on an invoice and wrong on a
     * tile: two decimal places there are noise the shopper reads past on every
     * card, and the pill is a fixed box that a longer string only makes the
     * type smaller inside. Whole dinars here; the exact figure is on the
     * product page, one tap away.
     *
     * It lived as a closure inside the catalogue template until the home page
     * needed the same pill, which is the moment a private format becomes a
     * shared one.
     */
    public static function badge(int $cents): string
    {
        $currency = str_starts_with((string) Config::get('app.locale', 'ar'), 'ar') ? 'د.أ' : 'JD';

        return number_format($cents / 100, 0, '', ',') . ' ' . $currency;
    }
}
