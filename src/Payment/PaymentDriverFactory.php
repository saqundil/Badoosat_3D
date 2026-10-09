<?php

declare(strict_types=1);

namespace App\Payment;

use App\Support\Config;

/**
 * Chooses by configured name, falling back to the first driver that says it is
 * available. Registering a gateway is one line in $drivers and one class.
 */
final class PaymentDriverFactory
{
    /** @var array<string,class-string<PaymentDriverInterface>> */
    private const DRIVERS = [
        'cod' => CashOnDeliveryDriver::class,
    ];

    public static function make(?string $name = null): PaymentDriverInterface
    {
        $name ??= (string) (Config::get('app.payment_driver') ?? 'cod');

        $class  = self::DRIVERS[$name] ?? null;
        $driver = $class === null ? null : new $class();

        if ($driver !== null && $driver->isAvailable()) {
            return $driver;
        }

        foreach (self::DRIVERS as $candidate) {
            $fallback = new $candidate();

            if ($fallback->isAvailable()) {
                return $fallback;
            }
        }

        return new CashOnDeliveryDriver();
    }

    /** @return array<int,PaymentDriverInterface> everything a customer may choose */
    public static function available(): array
    {
        $drivers = [];

        foreach (self::DRIVERS as $class) {
            $driver = new $class();

            if ($driver->isAvailable()) {
                $drivers[] = $driver;
            }
        }

        return $drivers;
    }
}
