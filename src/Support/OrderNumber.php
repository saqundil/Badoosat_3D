<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TS-4X7K2, not 1041.
 *
 * A sequential id tells a competitor how many orders were placed last month and
 * tells a stranger that guessing 1042 might work. It is also miserable to read
 * out: a customer phoning about "order forty-one" is one of several. The
 * alphabet drops I, O, 0 and 1, so nothing in a number is ambiguous when it is
 * written on a box by hand or read down a bad line.
 */
final class OrderNumber
{
    public static function generate(): string
    {
        $config   = Config::file('shop')['order_number'];
        $alphabet = (string) $config['alphabet'];
        $length   = (int) $config['length'];
        $last     = strlen($alphabet) - 1;

        $body = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= $alphabet[random_int(0, $last)];
        }

        return $config['prefix'] . $body;
    }

    /**
     * Loose on purpose. A customer typing their number into the tracking page
     * gets the benefit of the doubt on case and on the dash; what they may not
     * do is put a character in that the generator never emits.
     */
    public static function normalise(string $input): string
    {
        $config = Config::file('shop')['order_number'];
        $prefix = (string) $config['prefix'];

        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        $bare  = preg_replace('/^' . preg_quote(rtrim($prefix, '-'), '/') . '/', '', $clean) ?? $clean;

        return $prefix . $bare;
    }

    public static function looksValid(string $number): bool
    {
        $config  = Config::file('shop')['order_number'];
        $pattern = '/^' . preg_quote((string) $config['prefix'], '/')
            . '[' . preg_quote((string) $config['alphabet'], '/') . ']{' . (int) $config['length'] . '}$/';

        return preg_match($pattern, $number) === 1;
    }

    /**
     * Phone numbers are compared as digits alone. The same person will write
     * 0790000000, +962 79 000 0000 and 079-000-0000 on three different days.
     */
    public static function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // A local number and its international form are the same subscriber.
        $digits = preg_replace('/^(?:00)?962/', '', $digits) ?? $digits;

        return ltrim($digits, '0');
    }
}
