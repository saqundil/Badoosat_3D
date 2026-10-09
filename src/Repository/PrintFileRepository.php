<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * One press file per artwork and printed side, not per garment.
 *
 * A team ordering ten shirts across five sizes sends one design to the press;
 * the transfer sheet does not change because the shirt under it is an XL. Keying
 * this table on the artwork rather than the order line is what makes that true
 * in the data as well as on the shop floor, and it is why a ten-piece order
 * produces one job and one file.
 *
 * `side` is 'single' for a design with one file -- every design before the
 * editor could print both sides, and every upload since. A design that differs
 * front and back has a 'front' and a 'back' row instead, two sheets for two
 * transfers.
 */
final class PrintFileRepository extends Repository
{
    public function insert(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO print_files
                (custom_design_id, design_id, side, order_id, driver, colour_space, pdf_path, preview_path, dpi)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['custom_design_id'] ?? null,
            $values['design_id'] ?? null,
            $values['side'] ?? 'single',
            $values['order_id'] ?? null,
            $values['driver'],
            $values['colour_space'],
            $values['pdf_path'],
            $values['preview_path'],
            $values['dpi'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** A design's main file: its only one, or its front when it has two. */
    public function forCustomDesign(int $customDesignId): ?array
    {
        return $this->allForCustomDesign($customDesignId)[0] ?? null;
    }

    /** One side's file, or null until it has been produced. */
    public function forCustomDesignSide(int $customDesignId, string $side): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM print_files WHERE custom_design_id = ? AND side = ?');
        $stmt->execute([$customDesignId, $side]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> single, or front then back */
    public function allForCustomDesign(int $customDesignId): array
    {
        // side is an ENUM, so this sorts in its declared order: single, front, back.
        $stmt = $this->pdo()->prepare('SELECT * FROM print_files WHERE custom_design_id = ? ORDER BY side');
        $stmt->execute([$customDesignId]);

        return $stmt->fetchAll();
    }

    /** Reached through the line the admin screen is looking at. */
    public function forOrderItem(int $orderItemId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT pf.* FROM print_files pf
               JOIN order_items oi ON oi.custom_design_id = pf.custom_design_id
              WHERE oi.id = ?
              ORDER BY pf.side
              LIMIT 1'
        );
        $stmt->execute([$orderItemId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Distinct files for an order: DISTINCT because ten lines sharing one
     * design must not put the same sheet in the export ten times.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT DISTINCT pf.* FROM print_files pf
               JOIN order_items oi ON oi.custom_design_id = pf.custom_design_id
              WHERE oi.order_id = ?
              ORDER BY pf.custom_design_id ASC, pf.side'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<int,int> $orderIds
     * @return array<int,array<string,mixed>> one row per file, with its order
     */
    public function forOrders(array $orderIds): array
    {
        $ids = self::ints($orderIds);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT DISTINCT pf.*, oi.order_id
               FROM print_files pf
               JOIN order_items oi ON oi.custom_design_id = pf.custom_design_id
              WHERE oi.order_id IN (' . self::placeholders(count($ids)) . ')
              ORDER BY oi.order_id ASC, pf.custom_design_id ASC, pf.side'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }
}
