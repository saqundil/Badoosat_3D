<?php

declare(strict_types=1);

/*
 * Order history stops depending on the catalogue's good behaviour.
 *
 * `order_items` carried no foreign key at all on `variant_id` or `product_id`.
 * Deleting a product cascaded through `product_variants` and left every line
 * that had ever been sold pointing at a row that was no longer there -- 188 of
 * them in one test. Nothing crashed, because a line copies the product name,
 * the colour and the size onto itself, but the link back to the catalogue was
 * gone for good and restock could no longer find the variant to credit.
 *
 * Two keys, both RESTRICT: a variant that has been sold cannot be deleted, and
 * because deleting a product cascades to its variants, a product that has been
 * sold cannot be deleted either. The application refuses first, with a sentence
 * an administrator can act on; this is the floor under that, for anything that
 * reaches the database another way.
 *
 * Both columns are nullable and stay nullable -- a line recorded before either
 * column existed keeps its NULL, and a NULL is exempt from a foreign key.
 */

return static function (PDO $pdo): void {
    $dangling = (int) $pdo->query(
        'SELECT COUNT(*) FROM order_items oi
           LEFT JOIN product_variants v ON v.id = oi.variant_id
          WHERE oi.variant_id IS NOT NULL AND v.id IS NULL'
    )->fetchColumn();

    $orphanProducts = (int) $pdo->query(
        'SELECT COUNT(*) FROM order_items oi
           LEFT JOIN products p ON p.id = oi.product_id
          WHERE oi.product_id IS NOT NULL AND p.id IS NULL'
    )->fetchColumn();

    /*
     * A key cannot be added over rows that already break it. Rather than
     * rewrite history to fit -- which would mean editing what a customer was
     * sold -- the reference is released to NULL and the line keeps every copied
     * name it was printed with. Said out loud, because it is a real change to
     * real rows and whoever runs this deserves to know it happened.
     */
    if ($dangling > 0 || $orphanProducts > 0) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, '  ' . $dangling . ' order line(s) point at a deleted variant and '
                . $orphanProducts . " at a deleted product;\n"
                . "  those references are being set to NULL so the keys can be added.\n"
                . "  The lines themselves, and the names on them, are untouched.\n");
        }

        $pdo->exec(
            'UPDATE order_items oi
               LEFT JOIN product_variants v ON v.id = oi.variant_id
                SET oi.variant_id = NULL
              WHERE oi.variant_id IS NOT NULL AND v.id IS NULL'
        );

        $pdo->exec(
            'UPDATE order_items oi
               LEFT JOIN products p ON p.id = oi.product_id
                SET oi.product_id = NULL
              WHERE oi.product_id IS NOT NULL AND p.id IS NULL'
        );
    }

    $pdo->exec('ALTER TABLE order_items ADD INDEX order_items_variant (variant_id)');
    $pdo->exec('ALTER TABLE order_items ADD INDEX order_items_product (product_id)');

    $pdo->exec(
        'ALTER TABLE order_items
            ADD CONSTRAINT order_items_variant_fk FOREIGN KEY (variant_id)
                REFERENCES product_variants (id) ON DELETE RESTRICT,
            ADD CONSTRAINT order_items_product_fk FOREIGN KEY (product_id)
                REFERENCES products (id) ON DELETE RESTRICT'
    );
};
