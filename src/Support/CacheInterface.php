<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Services depend on this, never on a concrete store, so a Redis-backed
 * implementation can replace the file one without touching them.
 */
interface CacheInterface
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, ?int $ttl = null): void;

    public function forget(string $key): void;

    public function remember(string $key, callable $producer, ?int $ttl = null): mixed;
}
