<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * One press file per artwork, not per garment.
 *
 * A team ordering ten shirts across five sizes sends one design to the press;
 * the transfer sheet does not change because the shirt under it is an XL. Keying
 * this table on the artwork rather than the order line is what makes that true
 * in the data as well as on the shop floor, and it is why a ten-piece order
 * produces one job and one file.
 */
final class PrintFileRepository extends Repository
{
    public function insert(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO print_files
                (custom_design_id, design_id, order_id, driver, colour_space, pdf_path, preview_path, dpi)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['custom_design_id'] ?? null,
            $values['design_id'] ?? null,
            $values['order_id'] ?? null,
            $values['driver'],
            $values['colour_space'],
            $values['pdf_path'],
            $values['preview_path'],
            $values['dpi'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function forCustomDesign(int $customDesignId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM print_files WHERE custom_design_id = ?');
        $stmt->execute([$customDesignId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Reached through the line the admin screen is looking at. */
    public function forOrderItem(int $orderItemId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT pf.* FROM print_files pf
               JOIN order_items oi ON oi.custom_design_id = pf.custom_design_id
              WHERE oi.id = ?
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
              ORDER BY pf.id ASC'
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
              ORDER BY oi.order_id ASC, pf.id ASC'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }
}
