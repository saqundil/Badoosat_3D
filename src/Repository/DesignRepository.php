<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The shop's own ready-made designs.
 *
 * A design is not a product. It has no stock, no colour and no size -- it is
 * artwork that gets printed onto a garment that has all three. Modelling each
 * design as its own product would multiply the variant table by the size of the
 * design catalogue and put a stock number on something printed to order.
 *
 * Customer artwork lives in custom_designs and is reached through
 * CustomDesignRepository. The two are separate tables because they answer to
 * different people: one is merchandise the shop maintains, the other is a
 * file a customer handed over.
 */
final class DesignRepository extends Repository
{
    /** @return array<int,array<string,mixed>> */
    public function active(): array
    {
        return $this->pdo()
            ->query('SELECT * FROM designs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    /** @return array<int,array<string,mixed>> everything, for the admin list */
    public function all(): array
    {
        return $this->pdo()
            ->query('SELECT * FROM designs ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
    }

    /** Ready-made designs printable on one garment. @return array<int,array<string,mixed>> */
    public function forProduct(int $productId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT d.* FROM designs d
               JOIN design_products dp ON dp.design_id = d.id
              WHERE dp.product_id = ? AND d.is_active = 1
              ORDER BY d.sort_order ASC, d.id ASC'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    /** @return array<int,int> all linked design ids, including inactive designs */
    public function idsForProduct(int $productId): array
    {
        $stmt = $this->pdo()->prepare('SELECT design_id FROM design_products WHERE product_id = ?');
        $stmt->execute([$productId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM designs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM designs WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function layersForDesign(int $designId): array
    {
        return $this->layersForDesigns([$designId])[$designId] ?? [];
    }

    /** @param array<int,int> $designIds @return array<int,array<int,array<string,mixed>>> */
    public function layersForDesigns(array $designIds): array
    {
        $designIds = self::ints($designIds);

        if ($designIds === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT * FROM design_layers WHERE design_id IN (' . self::placeholders(count($designIds)) . ')
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute($designIds);
        $layers = [];

        foreach ($stmt->fetchAll() as $row) {
            $layers[(int) $row['design_id']][] = $row;
        }

        return $layers;
    }

    /** @param array<int,array{image_path:string,side:string,x:int,y:int,scale:int}> $layers */
    public function replaceLayers(int $designId, array $layers): void
    {
        $this->pdo()->prepare('DELETE FROM design_layers WHERE design_id = ?')->execute([$designId]);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO design_layers
                (design_id, image_path, side, position_x, position_y, scale_pct, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($layers as $order => $layer) {
            $stmt->execute([
                $designId,
                $layer['image_path'],
                $layer['side'],
                $layer['x'],
                $layer['y'],
                $layer['scale'],
                $order,
            ]);
        }
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
            'SELECT * FROM designs WHERE id IN (' . self::placeholders(count($ids)) . ')'
        );
        $stmt->execute($ids);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /** Is this design allowed on this garment? Asked before every add to cart. */
    public function isAllowedOn(int $designId, int $productId): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM design_products WHERE design_id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->execute([$designId, $productId]);

        return $stmt->fetchColumn() !== false;
    }

    public function insert(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO designs (slug, name, summary, print_path, thumb_path, surcharge_cents, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['slug'],
            $values['name'],
            $values['summary'] ?? null,
            $values['print_path'],
            $values['thumb_path'] ?? null,
            (int) $values['surcharge_cents'],
            (int) $values['is_active'],
            (int) $values['sort_order'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(int $id, array $values): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE designs SET slug = ?, name = ?, summary = ?, print_path = ?, thumb_path = ?,
                    surcharge_cents = ?, is_active = ?, sort_order = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $values['slug'],
            $values['name'],
            $values['summary'] ?? null,
            $values['print_path'],
            $values['thumb_path'] ?? null,
            (int) $values['surcharge_cents'],
            (int) $values['is_active'],
            (int) $values['sort_order'],
            $id,
        ]);
    }

    /**
     * Removes a design. Its layers and its product links go with it through
     * the foreign keys; the files on disk are the caller's to clear.
     */
    public function delete(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM designs WHERE id = ?')->execute([$id]);
    }

    /** @param array<int,int> $productIds replaces the whole set for this design */
    public function setProducts(int $designId, array $productIds): void
    {
        $this->pdo()->prepare('DELETE FROM design_products WHERE design_id = ?')->execute([$designId]);

        $stmt = $this->pdo()->prepare(
            'INSERT INTO design_products (design_id, product_id) VALUES (?, ?)'
        );

        foreach (self::ints($productIds) as $productId) {
            $stmt->execute([$designId, $productId]);
        }
    }

    /** @param array<int,int> $designIds replaces the whole design set for this product */
    public function setDesignsForProduct(int $productId, array $designIds): void
    {
        $this->pdo()->prepare('DELETE FROM design_products WHERE product_id = ?')->execute([$productId]);

        $stmt = $this->pdo()->prepare(
            'INSERT INTO design_products (design_id, product_id) VALUES (?, ?)'
        );

        foreach (self::ints($designIds) as $designId) {
            $stmt->execute([$designId, $productId]);
        }
    }

    /** @return array<int,int> product ids this design is offered on */
    public function productsFor(int $designId): array
    {
        $stmt = $this->pdo()->prepare('SELECT product_id FROM design_products WHERE design_id = ?');
        $stmt->execute([$designId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
