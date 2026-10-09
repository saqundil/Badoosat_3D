<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use PDO;
use Throwable;

/** PDO only. Every statement is prepared; no business rules live here. */
final class HeroSlideRepository
{
    private const COLUMNS = [
        'has_model', 'model_key', 'color_id', 'design_id', 'placement', 'show_badges',
        'parallax_enabled',
        'model_path', 'poster_path', 'garment_color', 'design_image_path',
        'background_value', 'tab_word', 'wordmark', 'tagline', 'stats', 'layout',
        'cta_label', 'cta_url', 'sort_order', 'is_active',
    ];

    /**
     * The connection is optional and resolved on first use. A repository built
     * for a request that ends up served entirely from cache must never open a
     * socket, or the cache cannot protect the page from a database outage.
     */
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Db::pdo();
    }

    public static function make(): self
    {
        return new self();
    }

    /** @return array<int,array<string,mixed>> */
    public function active(): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM hero_slides ORDER BY sort_order ASC, id ASC');
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM hero_slides WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insert(array $values): int
    {
        $columns      = implode(', ', self::COLUMNS);
        $placeholders = implode(', ', array_fill(0, count(self::COLUMNS), '?'));

        $stmt = $this->pdo()->prepare('INSERT INTO hero_slides (' . $columns . ') VALUES (' . $placeholders . ')');
        $stmt->execute($this->ordered($values));

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(int $id, array $values): void
    {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', self::COLUMNS));

        $stmt = $this->pdo()->prepare('UPDATE hero_slides SET ' . $set . ' WHERE id = ?');
        $stmt->execute([...$this->ordered($values), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM hero_slides WHERE id = ?')->execute([$id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo()->prepare('UPDATE hero_slides SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    /** @param array<int,int> $ids in the order they should appear */
    public function reorder(array $ids): void
    {
        $stmt = $this->pdo()->prepare('UPDATE hero_slides SET sort_order = ? WHERE id = ?');

        $this->pdo()->beginTransaction();

        try {
            foreach (array_values($ids) as $position => $id) {
                $stmt->execute([$position, (int) $id]);
            }

            $this->pdo()->commit();
        } catch (Throwable $e) {
            $this->pdo()->rollBack();

            throw $e;
        }
    }

    public function nextSortOrder(): int
    {
        $stmt = $this->pdo()->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM hero_slides');

        return (int) ($stmt->fetch()['next'] ?? 0);
    }

    /** @return list<mixed> */
    private function ordered(array $values): array
    {
        return array_map(static fn (string $column): mixed => $values[$column] ?? null, self::COLUMNS);
    }
}
