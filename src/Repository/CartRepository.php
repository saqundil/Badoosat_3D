<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The cart is a row, not a session key.
 *
 * A session cart dies with the PHP session, which on shared hosting is a
 * garbage-collected file with a lifetime nobody controls -- and it cannot be
 * merged into an account, because there is nothing to merge. A token in a
 * cookie pointing at a row survives the session, survives a deploy, and can be
 * handed to a customer record the moment they sign in.
 */
final class CartRepository extends Repository
{
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM carts WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findForCustomer(int $customerId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM carts WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $token, ?int $customerId = null): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO carts (token, customer_id) VALUES (?, ?)');
        $stmt->execute([$token, $customerId]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function claim(int $cartId, int $customerId): void
    {
        $this->pdo()
            ->prepare('UPDATE carts SET customer_id = ? WHERE id = ?')
            ->execute([$customerId, $cartId]);
    }

    public function touch(int $cartId): void
    {
        $this->pdo()
            ->prepare('UPDATE carts SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$cartId]);
    }

    /**
     * Every line with the garment, colour, size and both kinds of design
     * resolved. One query: a cart page that joins per line is the same N+1 the
     * catalogue page is forbidden from having, and carts are read constantly.
     *
     * @return array<int,array<string,mixed>>
     */
    public function items(int $cartId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT ci.*,
                    v.sku, v.price_cents AS variant_price_cents, v.stock, v.is_active AS variant_active,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    p.image_path, p.garment, p.is_active AS product_active,
                    c.name AS color_name, c.hex AS color_hex,
                    s.code AS size_code, s.label AS size_label,
                    d.name AS design_name, d.slug AS design_slug, d.thumb_path AS design_thumb,
                    d.surcharge_cents AS design_surcharge_cents, d.is_active AS design_active,
                    cd.token AS custom_token, cd.preview_path AS custom_preview,
                    cd.quality AS custom_quality, cd.source AS custom_source
               FROM cart_items ci
               JOIN product_variants v ON v.id = ci.variant_id
               JOIN products p ON p.id = v.product_id
               JOIN colors   c ON c.id = v.color_id
               JOIN sizes    s ON s.id = v.size_id
          LEFT JOIN designs        d  ON d.id  = ci.design_id
          LEFT JOIN custom_designs cd ON cd.id = ci.custom_design_id
              WHERE ci.cart_id = ?
              ORDER BY ci.id ASC'
        );
        $stmt->execute([$cartId]);

        return $stmt->fetchAll();
    }

    public function countItems(int $cartId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COALESCE(SUM(quantity), 0) AS n FROM cart_items WHERE cart_id = ?'
        );
        $stmt->execute([$cartId]);

        return (int) $stmt->fetchColumn();
    }

    public function lineCount(int $cartId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?');
        $stmt->execute([$cartId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The same garment, design and placement added twice is one line with a
     * larger quantity, not two lines -- which is also what makes the quantity
     * discount work the way a customer expects.
     */
    public function findSame(int $cartId, array $line): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM cart_items
              WHERE cart_id = ? AND variant_id = ? AND item_type = ? AND placement = ?
                AND design_id <=> ? AND custom_design_id <=> ?
              LIMIT 1'
        );
        $stmt->execute([
            $cartId,
            $line['variant_id'],
            $line['item_type'],
            $line['placement'],
            $line['design_id'],
            $line['custom_design_id'],
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insert(int $cartId, array $line): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO cart_items
                (cart_id, item_type, variant_id, design_id, custom_design_id, placement, quantity)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cartId,
            $line['item_type'],
            $line['variant_id'],
            $line['design_id'],
            $line['custom_design_id'],
            $line['placement'],
            $line['quantity'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function setQuantity(int $cartId, int $itemId, int $quantity): void
    {
        $this->pdo()
            ->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND cart_id = ?')
            ->execute([$quantity, $itemId, $cartId]);
    }

    public function removeItem(int $cartId, int $itemId): void
    {
        $this->pdo()
            ->prepare('DELETE FROM cart_items WHERE id = ? AND cart_id = ?')
            ->execute([$itemId, $cartId]);
    }

    public function clear(int $cartId): void
    {
        $this->pdo()->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);
    }

    /**
     * Moves a guest cart's lines onto the account's cart and drops the empty
     * guest row. Lines that already exist on the target are added to rather
     * than duplicated, so signing in never silently doubles an order.
     */
    public function merge(int $fromCartId, int $intoCartId): void
    {
        if ($fromCartId === $intoCartId) {
            return;
        }

        $this->pdo()->beginTransaction();

        try {
            foreach ($this->items($fromCartId) as $line) {
                $existing = $this->findSame($intoCartId, [
                    'variant_id'       => (int) $line['variant_id'],
                    'item_type'        => $line['item_type'],
                    'placement'        => $line['placement'],
                    'design_id'        => $line['design_id'] === null ? null : (int) $line['design_id'],
                    'custom_design_id' => $line['custom_design_id'] === null ? null : (int) $line['custom_design_id'],
                ]);

                if ($existing === null) {
                    $this->pdo()
                        ->prepare('UPDATE cart_items SET cart_id = ? WHERE id = ?')
                        ->execute([$intoCartId, (int) $line['id']]);

                    continue;
                }

                $this->setQuantity(
                    $intoCartId,
                    (int) $existing['id'],
                    (int) $existing['quantity'] + (int) $line['quantity']
                );
                $this->pdo()->prepare('DELETE FROM cart_items WHERE id = ?')->execute([(int) $line['id']]);
            }

            $this->pdo()->prepare('DELETE FROM carts WHERE id = ?')->execute([$fromCartId]);
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();

            throw $e;
        }
    }
}
