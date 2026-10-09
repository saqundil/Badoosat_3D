<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;

/**
 * Every figure the shop charges, derived from the database row and config/shop.php.
 *
 * Nothing here takes an argument that came from a browser. Not the unit price,
 * not the design surcharge, not the quantity discount, not the shipping. The
 * cart page and the order writer both call this with rows they have just read,
 * so a customer who edits a hidden field, a JSON body or a price attribute in
 * their developer tools changes what their own screen says and nothing else --
 * the next recomputation, which happens before anything is charged, overwrites
 * it from the same source it came from the first time.
 *
 * The methods are static and take no collaborators on purpose: there is no
 * state here to get out of step, and a pricing rule that cannot be reached
 * without a database connection is a pricing rule that is hard to test.
 */
final class PricingService
{
    /** @return array<string,mixed> the shop's money settings, read fresh */
    public static function settings(): array
    {
        return Config::file('shop');
    }

    /**
     * What one piece costs before the quantity discount: the garment, plus
     * whatever is printed on it, plus the cost of printing it twice if the
     * customer asked for front and back.
     */
    public static function unitPrice(
        int $variantPriceCents,
        string $itemType,
        int $designSurchargeCents,
        string $placement
    ): int {
        $shop = self::settings();

        $surcharge = match ($itemType) {
            'ready'  => $designSurchargeCents,
            'custom' => (int) $shop['custom_surcharge_cents'],
            default  => 0,
        };

        if ($surcharge > 0 && $placement === 'both') {
            $surcharge = (int) round($surcharge * (float) $shop['both_sides_multiplier']);
        }

        return $variantPriceCents + $surcharge;
    }

    /** The printing part of a unit price, kept separately so an invoice can show it. */
    public static function surcharge(string $itemType, int $designSurchargeCents, string $placement): int
    {
        $shop = self::settings();

        $surcharge = match ($itemType) {
            'ready'  => $designSurchargeCents,
            'custom' => (int) $shop['custom_surcharge_cents'],
            default  => 0,
        };

        if ($surcharge > 0 && $placement === 'both') {
            $surcharge = (int) round($surcharge * (float) $shop['both_sides_multiplier']);
        }

        return $surcharge;
    }

    /**
     * Percentage off, by how many pieces are on the line.
     *
     * Per line rather than per order, because the saving is real per line: ten
     * of the same shirt in the same colour is one print run and one pull from
     * one shelf. Ten different shirts is ten of each.
     */
    public static function discountPercent(int $quantity): int
    {
        $tiers = self::settings()['quantity_discount'];
        krsort($tiers);

        foreach ($tiers as $from => $percent) {
            if ($quantity >= (int) $from) {
                return (int) $percent;
            }
        }

        return 0;
    }

    /**
     * @return array{unit_cents:int,surcharge_cents:int,gross_cents:int,
     *               discount_percent:int,discount_cents:int,total_cents:int}
     */
    public static function line(
        int $variantPriceCents,
        string $itemType,
        int $designSurchargeCents,
        string $placement,
        int $quantity
    ): array {
        $surcharge = self::surcharge($itemType, $designSurchargeCents, $placement);
        $unit      = $variantPriceCents + $surcharge;
        $gross     = $unit * $quantity;
        $percent   = self::discountPercent($quantity);

        // Floor, not round: rounding a discount up means charging a customer
        // less than the arithmetic says, every time, on every line.
        $discount = (int) floor($gross * $percent / 100);

        return [
            'unit_cents'       => $unit,
            'surcharge_cents'  => $surcharge,
            'gross_cents'      => $gross,
            'discount_percent' => $percent,
            'discount_cents'   => $discount,
            'total_cents'      => $gross - $discount,
        ];
    }

    /** Free above the threshold, measured on what the customer actually pays for goods. */
    public static function shipping(int $subtotalAfterDiscountCents): int
    {
        $shipping = self::settings()['shipping'];

        if ($subtotalAfterDiscountCents <= 0) {
            return 0;
        }

        return $subtotalAfterDiscountCents >= (int) $shipping['free_over_cents']
            ? 0
            : (int) $shipping['flat_cents'];
    }

    /** How much more is needed for free delivery, or zero when it is already free. */
    public static function shippingGap(int $subtotalAfterDiscountCents): int
    {
        $threshold = (int) self::settings()['shipping']['free_over_cents'];

        return max(0, $threshold - $subtotalAfterDiscountCents);
    }

    /**
     * Rolls priced lines into the figures a cart or an order shows.
     *
     * @param array<int,array<string,mixed>> $lines each with the keys line() returns
     * @return array{subtotal_cents:int,discount_cents:int,shipping_cents:int,
     *               total_cents:int,pieces:int,shipping_gap_cents:int}
     */
    public static function totals(array $lines): array
    {
        $gross    = 0;
        $discount = 0;
        $pieces   = 0;

        foreach ($lines as $line) {
            $gross    += (int) $line['gross_cents'];
            $discount += (int) $line['discount_cents'];
            $pieces   += (int) $line['quantity'];
        }

        $subtotal = $gross - $discount;
        $shipping = self::shipping($subtotal);

        return [
            'subtotal_cents'     => $gross,
            'discount_cents'     => $discount,
            'shipping_cents'     => $shipping,
            'total_cents'        => $subtotal + $shipping,
            'pieces'             => $pieces,
            'shipping_gap_cents' => self::shippingGap($subtotal),
        ];
    }
}
