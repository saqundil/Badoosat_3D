<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Artwork a customer supplied, however they supplied it.
 *
 * The 3D editor and the direct upload page write the same row. `source` records
 * which door it came through and `design_json` is filled only by the editor,
 * but nothing downstream branches on either: the print queue, the production
 * sheet and the admin screen read one shape. That is the point of the table --
 * a company sending a finished PDF and a customer dragging a logo around a
 * shirt produce the same kind of thing to print.
 */
final class CustomDesignRepository extends Repository
{
    public function insert(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO custom_designs
                (token, source, image_path, original_path, mime, width_px, height_px, dpi, quality,
                 placement, design_json, preview_path, source_path, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['token'],
            $values['source'] ?? 'editor',
            $values['image_path'],
            $values['original_path'] ?? null,
            $values['mime'],
            $values['width_px'],
            $values['height_px'],
            $values['dpi'],
            $values['quality'],
            $values['placement'],
            $values['design_json'] ?? null,
            $values['preview_path'] ?? null,
            $values['source_path'] ?? null,
            $values['note'] ?? null,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM custom_designs WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM custom_designs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>> keyed by id
     */
    public function findMany(array $ids): array
    {
        $ids = self::ints($ids);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT * FROM custom_designs WHERE id IN (' . self::placeholders(count($ids)) . ')'
        );
        $stmt->execute($ids);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }
}
