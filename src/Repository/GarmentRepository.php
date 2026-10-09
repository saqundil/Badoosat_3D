<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Lang;
use PDO;

/**
 * Garment types, and the colours and sizes each is cut in.
 *
 * This is the merchandising half of "garment". The other half -- UV rectangles
 * and centimetre scales measured against a mesh -- stays in config/garments.php
 * and is reached through App\Support\Garment, because it is programmer data
 * that no admin screen should be able to change.
 *
 * The two meet at config/garment-models.php: a row here stores a model key, the
 * registry turns it into a .glb path and names the geometry entry beside it.
 */
final class GarmentRepository extends Repository
{
    /** @return array<int,array<string,mixed>> every type, active or not */
    public function all(): array
    {
        return $this->pdo()
            ->query('SELECT * FROM garments ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    /** @return array<int,array<string,mixed>> the types a product may be built on */
    public function active(): array
    {
        return $this->pdo()
            ->query('SELECT * FROM garments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM garments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM garments WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function keyExists(string $key, int $exceptId = 0): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM garments WHERE `key` = ? AND id <> ? LIMIT 1');
        $stmt->execute([$key, $exceptId]);

        return $stmt->fetchColumn() !== false;
    }

    public function insert(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO garments
                (`key`, name_ar, name_en, model_key, base_product_id,
                 print_area_w_cm, print_area_h_cm, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['key'], $values['name_ar'], $values['name_en'], $values['model_key'],
            $values['base_product_id'],
            $values['print_area_w_cm'], $values['print_area_h_cm'],
            $values['is_active'], $values['sort_order'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(int $id, array $values): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE garments
                SET `key` = ?, name_ar = ?, name_en = ?, model_key = ?, base_product_id = ?,
                    print_area_w_cm = ?, print_area_h_cm = ?, is_active = ?, sort_order = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $values['key'], $values['name_ar'], $values['name_en'], $values['model_key'],
            $values['base_product_id'],
            $values['print_area_w_cm'], $values['print_area_h_cm'],
            $values['is_active'], $values['sort_order'], $id,
        ]);
    }

    /* ------------------------------------------------- the base product */

    /**
     * Products that may be a type's base product.
     *
     * Three conditions, and they are the editor's requirements read backwards:
     * it belongs to this type, it is on sale, and it has at least one active
     * variant -- without which there is no colour, no size, no price and no
     * stock for a custom order to become.
     *
     * Printed products are listed rather than filtered out. A shop may
     * deliberately sell custom work on a garment that already carries a print,
     * so the screen warns instead of refusing; see GarmentService::validate().
     *
     * @return array<int,array<string,mixed>>
     */
    public function baseProductCandidates(int $garmentId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT p.id, p.name, p.slug, p.artwork_path, p.base_price_cents,
                    (SELECT COUNT(*) FROM product_variants v
                      WHERE v.product_id = p.id AND v.is_active = 1) AS active_variants
               FROM products p
              WHERE p.garment_id = ? AND p.is_active = 1
                AND EXISTS (SELECT 1 FROM product_variants v
                             WHERE v.product_id = p.id AND v.is_active = 1)
              ORDER BY p.artwork_path IS NOT NULL, p.sort_order, p.id'
        );
        $stmt->execute([$garmentId]);

        return $stmt->fetchAll();
    }

    /**
     * Whether a product may serve as this type's base, and why not when it may
     * not. Returns null when it is fine.
     */
    public function baseProductProblem(int $garmentId, int $productId): ?string
    {
        $stmt = $this->pdo()->prepare(
            'SELECT p.id, p.name, p.garment_id, p.is_active,
                    (SELECT COUNT(*) FROM product_variants v
                      WHERE v.product_id = p.id AND v.is_active = 1) AS active_variants
               FROM products p WHERE p.id = ?'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return Lang::get('admin.garments.base.missing');
        }

        if ((int) $row['garment_id'] !== $garmentId) {
            return Lang::get('admin.garments.base.other_type');
        }

        if ((int) $row['is_active'] !== 1) {
            return Lang::get('admin.garments.base.inactive');
        }

        if ((int) $row['active_variants'] < 1) {
            return Lang::get('admin.garments.base.no_variants');
        }

        return null;
    }

    /**
     * The base product with everything the editor needs to mount on it, or null
     * when the type has none or the one it names can no longer serve.
     *
     * Re-checked on every read rather than trusted: the product may have been
     * deactivated, or had its last variant switched off, long after it was
     * chosen. The editor page turns a null here into a sentence for the
     * customer instead of a broken screen.
     */
    public function baseProductFor(int $garmentId): ?array
    {
        $row = $this->find($garmentId);
        $productId = (int) ($row['base_product_id'] ?? 0);

        if ($row === null || $productId < 1) {
            return null;
        }

        if ($this->baseProductProblem($garmentId, $productId) !== null) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        return $product === false ? null : $product;
    }

    /* -------------------------------------------------- colours and sizes */

    /** @return array<int,array<string,mixed>> the palette this type is cut in */
    public function colorsFor(int $garmentId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT c.* FROM garment_colors gc
               JOIN colors c ON c.id = gc.color_id
              WHERE gc.garment_id = ?
              ORDER BY gc.sort_order ASC, c.id ASC'
        );
        $stmt->execute([$garmentId]);

        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> the sizes this type is cut in */
    public function sizesFor(int $garmentId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT s.* FROM garment_sizes gs
               JOIN sizes s ON s.id = gs.size_id
              WHERE gs.garment_id = ?
              ORDER BY gs.sort_order ASC, s.id ASC'
        );
        $stmt->execute([$garmentId]);

        return $stmt->fetchAll();
    }

    /** @return array<int,int> */
    public function colorIdsFor(int $garmentId): array
    {
        return array_map('intval', array_column($this->colorsFor($garmentId), 'id'));
    }

    /** @return array<int,int> */
    public function sizeIdsFor(int $garmentId): array
    {
        return array_map('intval', array_column($this->sizesFor($garmentId), 'id'));
    }

    /**
     * Replaces the colours a type is cut in.
     *
     * Delete-then-insert rather than a diff: this is a small join table with no
     * data of its own to lose, and the caller runs it inside the transaction
     * that saves the type.
     *
     * @param array<int,int> $colorIds
     */
    public function setColors(int $garmentId, array $colorIds): void
    {
        $this->pdo()->prepare('DELETE FROM garment_colors WHERE garment_id = ?')->execute([$garmentId]);

        if ($colorIds === []) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO garment_colors (garment_id, color_id, sort_order) VALUES (?, ?, ?)'
        );

        foreach (self::ints($colorIds) as $index => $colorId) {
            $stmt->execute([$garmentId, $colorId, $index]);
        }
    }

    /** @param array<int,int> $sizeIds */
    public function setSizes(int $garmentId, array $sizeIds): void
    {
        $this->pdo()->prepare('DELETE FROM garment_sizes WHERE garment_id = ?')->execute([$garmentId]);

        if ($sizeIds === []) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO garment_sizes (garment_id, size_id, sort_order) VALUES (?, ?, ?)'
        );

        foreach (self::ints($sizeIds) as $index => $sizeId) {
            $stmt->execute([$garmentId, $sizeId, $index]);
        }
    }

    /** How many published products depend on this type. */
    public function productCount(int $garmentId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM products WHERE garment_id = ?');
        $stmt->execute([$garmentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Products that would lose a colour if the given set became the type's.
     *
     * Asked before a type is saved, so narrowing a type can say which products
     * it will affect instead of silently deactivating their variants.
     *
     * @param array<int,int> $colorIds
     * @return array<int,array<string,mixed>>
     */
    public function productsLosingColors(int $garmentId, array $colorIds): array
    {
        $ids = self::ints($colorIds);
        $notIn = $ids === [] ? '' : ' AND pc.color_id NOT IN (' . self::placeholders(count($ids)) . ')';

        $stmt = $this->pdo()->prepare(
            'SELECT p.id, p.name, p.slug, c.id AS color_id, c.name AS color_name
               FROM product_colors pc
               JOIN products p ON p.id = pc.product_id
               JOIN colors c   ON c.id = pc.color_id
              WHERE p.garment_id = ?' . $notIn . '
              ORDER BY p.id ASC, c.id ASC'
        );
        $stmt->execute([$garmentId, ...$ids]);

        return $stmt->fetchAll();
    }
}
