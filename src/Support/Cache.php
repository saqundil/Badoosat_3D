<?php

declare(strict_types=1);

namespace App\Support;

final class Cache
{
    private static ?CacheInterface $store = null;

    public static function store(): CacheInterface
    {
        if (self::$store instanceof CacheInterface) {
            return self::$store;
        }

        $config = Config::file('app')['cache'];

        return self::$store = match ($config['driver']) {
            default => new FileCache(Config::get('app.paths.cache'), (int) $config['ttl']),
        };
    }

    public static function swap(?CacheInterface $store): void
    {
        self::$store = $store;
    }
}
