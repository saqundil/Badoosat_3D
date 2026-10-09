<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use PDO;

/**
 * The connection is optional and resolved on first use.
 *
 * A repository built for a request that ends up served entirely from cache must
 * never open a socket, or the cache cannot protect the page from a database
 * outage -- which is most of the reason the catalogue page has a cache at all.
 */
abstract class Repository
{
    public function __construct(protected readonly ?PDO $connection = null)
    {
    }

    public static function make(): static
    {
        return new static();
    }

    protected function pdo(): PDO
    {
        return $this->connection ?? Db::pdo();
    }

    /** `?, ?, ?` for an IN clause, with the values cleaned to ints. */
    protected static function ints(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    protected static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
