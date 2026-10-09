<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\Config;

/**
 * Reads config/print.php and hands back a driver. Moving to a VPS is changing
 * 'driver' from 'auto' to 'shell'; no service is touched.
 */
final class PrintDriverFactory
{
    private const DRIVERS = [
        'imagick'     => ImagickDriver::class,
        'shell'       => ShellDriver::class,
        'passthrough' => PassthroughDriver::class,
    ];

    private static ?PrintDriverInterface $resolved = null;

    public static function make(?string $force = null): PrintDriverInterface
    {
        $choice = $force ?? (string) Config::get('print.driver', 'auto');

        if ($choice !== 'auto') {
            $class = self::DRIVERS[$choice] ?? null;

            if ($class === null) {
                throw new PrintDriverException('Unknown print driver: ' . $choice);
            }

            $driver = new $class();

            if (!$driver->isAvailable()) {
                throw new PrintDriverException('Print driver "' . $choice . '" is configured but not available here.');
            }

            return $driver;
        }

        foreach ((array) Config::get('print.order', array_keys(self::DRIVERS)) as $name) {
            $class = self::DRIVERS[$name] ?? null;

            if ($class === null) {
                continue;
            }

            $driver = new $class();

            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        return new PassthroughDriver();
    }

    /** Resolved once per request; the probe costs a process spawn. */
    public static function shared(): PrintDriverInterface
    {
        return self::$resolved ??= self::make();
    }

    public static function swap(?PrintDriverInterface $driver): void
    {
        self::$resolved = $driver;
    }

    /** @return array<string,bool> which drivers this host can actually run */
    public static function availability(): array
    {
        $report = [];

        foreach (self::DRIVERS as $name => $class) {
            $report[$name] = (new $class())->isAvailable();
        }

        return $report;
    }
}
