<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;

/**
 * Numbers, money and dates in the active locale, through ICU.
 *
 * The dashboard's formatter. money() in bootstrap.php is left exactly as it is
 * -- number_format and a fixed "د.أ" -- because the storefront prints it on
 * every price and nothing about the admin work should move a storefront digit.
 *
 * WESTERN DIGITS EVERYWHERE
 *
 * Arabic's ICU default is Arabic-Indic digits. The shop writes 0-9 on every
 * page, so every formatter here is built for "<locale>@numbers=latn": the
 * grouping, the decimal mark, the order of figure and currency and the month
 * names are the locale's, the digits are not.
 *
 * TIMES
 *
 * Database timestamps are naive local times, written and read in the app's
 * timezone (config app.timezone, applied by bootstrap.php). They are parsed in
 * that zone and formatted in it, so a row reads the same here as it did when
 * the orders table printed date('Y-m-d H:i').
 */
final class Format
{
    /** What the shop charges in; prices are stored in its cents (fils/10). */
    public const CURRENCY = 'JOD';

    public static function number(int|float $value, int $decimals = 0): string
    {
        $f = new NumberFormatter(self::icu(), NumberFormatter::DECIMAL);
        $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $f->format($value);
    }

    /** Whole string, e.g. "JOD 12.50" or "12.50 د.أ." */
    public static function money(int $cents): string
    {
        return (string) self::currencyFormatter()->formatCurrency($cents / 100, self::CURRENCY);
    }

    /**
     * The figure and the currency mark apart, so the mark can be set smaller
     * and lighter than the number -- and in the order the locale puts them.
     *
     * ICU keeps two decimals only because it is told to: JOD is a three-decimal
     * currency in CLDR, and the shop has never priced in fils.
     *
     * @return array{figure:string,symbol:string,before:bool}
     */
    public static function moneyParts(int $cents): array
    {
        $whole  = self::money($cents);
        $figure = self::number($cents / 100, 2);
        $at     = mb_strpos($whole, $figure);

        // Bidi marks and spaces belong to neither half. A regex and not trim():
        // trim() works byte by byte, and U+061C begins with the same byte as
        // the Arabic letter dal, so "د.أ." used to lose its first letter.
        $symbol = (string) preg_replace(
            '/^[\s\x{00A0}\x{202F}\x{200E}\x{200F}\x{061C}]+|[\s\x{00A0}\x{202F}\x{200E}\x{200F}\x{061C}]+$/u',
            '',
            str_replace($figure, '', $whole),
        );

        return [
            'figure' => $figure,
            'symbol' => $symbol,
            'before' => $at !== false && $at > 0 && mb_strpos($whole, $symbol) < $at,
        ];
    }

    /** 0.125 → "12.5%" */
    public static function percent(float $ratio): string
    {
        $f = new NumberFormatter(self::icu(), NumberFormatter::PERCENT);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return (string) $f->format($ratio);
    }

    /**
     * @param 'date'|'datetime'|'day' $style  day is "12 Sep" -- the list rows
     */
    public static function date(string|DateTimeInterface $when, string $style = 'date'): string
    {
        $f = match ($style) {
            'datetime' => new IntlDateFormatter(self::icu(), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, self::zone()),
            'day'      => new IntlDateFormatter(self::icu(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, self::zone(), null, 'd MMM'),
            default    => new IntlDateFormatter(self::icu(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, self::zone()),
        };

        return (string) $f->format(self::moment($when));
    }

    /** The value to put in <time datetime="">. */
    public static function iso(string|DateTimeInterface $when): string
    {
        return self::moment($when)->format(DateTimeInterface::ATOM);
    }

    /**
     * "Today", "Yesterday", "3 days ago", and a plain date after a month --
     * a relative age stops meaning anything once it has to be counted.
     */
    public static function ago(string|DateTimeInterface $when, ?DateTimeInterface $now = null): string
    {
        $then  = self::moment($when)->setTime(0, 0);
        $today = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable('now', self::zone()))->setTime(0, 0);
        $days  = (int) $then->diff($today)->format('%r%a');

        return match (true) {
            $days <= 0  => Lang::get('admin.time.today'),
            $days === 1 => Lang::get('admin.time.yesterday'),
            $days <= 30 => self::plural('admin.time.days_ago', $days),
            default     => self::date($when),
        };
    }

    /**
     * An ICU plural message from the lang file, e.g.
     * '{n, plural, one{# piece} other{# pieces}}'. Arabic has six forms and ICU
     * knows which one a number takes; Lang deliberately does not.
     */
    public static function plural(string $key, int $n): string
    {
        $pattern = Lang::get($key);
        $text    = MessageFormatter::formatMessage(self::icu(), $pattern, ['n' => $n]);

        return $text === false ? $pattern : $text;
    }

    private static function icu(): string
    {
        return Lang::locale() . '@numbers=latn';
    }

    private static function currencyFormatter(): NumberFormatter
    {
        $f = new NumberFormatter(self::icu(), NumberFormatter::CURRENCY);
        $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return $f;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private static function moment(string|DateTimeInterface $when): DateTimeImmutable
    {
        return $when instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($when)->setTimezone(self::zone())
            : new DateTimeImmutable($when, self::zone());
    }
}
