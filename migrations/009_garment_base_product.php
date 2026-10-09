<?php

declare(strict_types=1);

/*
 * The product a custom order of a cut is sold as.
 *
 * The 3D editor was built product-first: its config requires a product_id and a
 * colour+size-to-variant map, and when the customer is done it posts straight to
 * /cart.php with that product_id. It has to -- cart_items.variant_id is NOT NULL,
 * so a line has to point at a variant, and variants belong to products. Price
 * and stock live there too; a garment type carries neither.
 *
 * The customer, though, should be choosing a CUT, not hunting for a product to
 * hang their artwork on. So the type names one product as the thing a custom
 * order of it becomes, and the editor page resolves cut -> base product ->
 * variants before it mounts. The customer sees a cut, a colour and a size; the
 * product is plumbing they never meet.
 *
 * Nullable on purpose. A type with no base product is not broken, it is not
 * finished -- and the rule is that such a type does not appear in the editor at
 * all, with the reason said out loud on the admin screen rather than the
 * customer meeting a cut they cannot order.
 *
 * SET NULL rather than CASCADE or RESTRICT: deleting the base product should
 * take that cut out of the editor, not delete the cut and not refuse the
 * delete. The admin screen then says the type needs one again.
 */

return static function (PDO $pdo): void {
    $report = static function (string $line): void {
        fwrite(STDOUT, '      ' . $line . PHP_EOL);
    };

    $pdo->exec(
        'ALTER TABLE garments
            ADD COLUMN base_product_id INT UNSIGNED NULL AFTER model_key,
            ADD CONSTRAINT garments_base_product_fk
                FOREIGN KEY (base_product_id) REFERENCES products (id) ON DELETE SET NULL'
    );

    $report('garments.base_product_id added (nullable, ON DELETE SET NULL)');

    /*
     * Not filled in automatically.
     *
     * Guessing "the first plain product of this type" would quietly decide what
     * every custom order of that cut is sold as -- its price, its stock, its
     * name on the packing slip. That is the shop owner's call, and a wrong
     * guess would be invisible until an order came in against it. The admin
     * screen asks for it and says why the editor is empty until it has one.
     */
    $types = $pdo->query('SELECT id, `key`, name_ar FROM garments ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);

    $report('types needing a base product before they appear in the editor:');

    foreach ($types as $type) {
        $candidates = $pdo->prepare(
            'SELECT COUNT(*) FROM products p
              WHERE p.garment_id = ? AND p.is_active = 1 AND p.artwork_path IS NULL
                AND EXISTS (SELECT 1 FROM product_variants v
                             WHERE v.product_id = p.id AND v.is_active = 1)'
        );
        $candidates->execute([(int) $type['id']]);

        $report(sprintf('  #%d %-22s %d eligible plain product(s) to choose from',
            (int) $type['id'], $type['key'], (int) $candidates->fetchColumn()));
    }
};
