<?php

declare(strict_types=1);

/*
 * Garment types become rows.
 *
 * Until now "which garment is this" was a VARCHAR on the product holding a key
 * into config/garments.php -- a file only a programmer can edit. That file
 * carries UV rectangles and centimetre scales measured against a specific
 * mesh, which is programmer data and stays where it is. What it should never
 * have carried is the merchandising side: what the type is called, which
 * colours it comes in, which sizes it is cut in. Those are the shop owner's,
 * and they now live in `garments`, `garment_colors` and `garment_sizes`.
 *
 * The two are joined through config/garment-models.php: a type stores a model
 * key, the registry turns that key into a .glb path and names the geometry
 * entry that goes with it. No path is ever typed in and nothing is uploaded.
 *
 * A product then chooses a type, and its colours are a subset of the type's.
 * Sizes are not chosen per product at all -- a cut is made in the sizes it is
 * made in -- so variants become product_colors x garment_sizes.
 *
 * ADDITIVE ONLY. Nothing is dropped and nothing is deleted. products.garment,
 * presentation_type, design_x/y/scale and show_3d all stay exactly where they
 * are, still written, still read, because designs, design_products and
 * design_layers still hold live order history. Phase 4 removes them, by
 * decision, once the inventory has been reviewed.
 */

return static function (PDO $pdo): void {
    $report = static function (string $line): void {
        fwrite(STDOUT, '      ' . $line . PHP_EOL);
    };

    /* ------------------------------------------------------------ garments */

    $pdo->exec(
        "CREATE TABLE garments (
            id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `key`       VARCHAR(60) NOT NULL,
            name_ar     VARCHAR(120) NOT NULL,
            name_en     VARCHAR(120) NOT NULL,
            -- A key in config/garment-models.php, never a path. Same rule the
            -- hero follows: the registry is the only set of .glb files this
            -- screen can reach, and it is edited in the repository.
            model_key   VARCHAR(60) NOT NULL,
            -- The area artwork is placed in, in centimetres. Distinct from the
            -- printable panel in config/garments.php, which is the limit of
            -- where ink CAN go rather than the size anything is printed at.
            print_area_w_cm DECIMAL(5,1) NOT NULL DEFAULT 28.0,
            print_area_h_cm DECIMAL(5,1) NOT NULL DEFAULT 35.0,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY garments_key (`key`),
            INDEX garments_listing (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /*
     * RESTRICT on the colour and size keys, not CASCADE: retiring a colour from
     * the palette must not silently empty a garment type of its colours. The
     * palette is small and shared, and a delete there is already refused
     * wherever a variant uses it.
     */
    $pdo->exec(
        'CREATE TABLE garment_colors (
            garment_id  SMALLINT UNSIGNED NOT NULL,
            color_id    SMALLINT UNSIGNED NOT NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (garment_id, color_id),
            INDEX garment_colors_color (color_id),
            CONSTRAINT garment_colors_garment_fk FOREIGN KEY (garment_id) REFERENCES garments (id) ON DELETE CASCADE,
            CONSTRAINT garment_colors_color_fk   FOREIGN KEY (color_id)   REFERENCES colors (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE garment_sizes (
            garment_id  SMALLINT UNSIGNED NOT NULL,
            size_id     SMALLINT UNSIGNED NOT NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (garment_id, size_id),
            INDEX garment_sizes_size (size_id),
            CONSTRAINT garment_sizes_garment_fk FOREIGN KEY (garment_id) REFERENCES garments (id) ON DELETE CASCADE,
            CONSTRAINT garment_sizes_size_fk    FOREIGN KEY (size_id)    REFERENCES sizes (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    /* ----------------------------------------------------- the two seed rows */

    $pdo->exec(
        "INSERT INTO garments (`key`, name_ar, name_en, model_key, print_area_w_cm, print_area_h_cm, sort_order)
         VALUES
           ('premium-basic-cut',   'قصّة أساسية فاخرة', 'Premium Basic Cut',   'premium-basic',   28.0, 35.0, 0),
           ('graphic-premium-cut', 'قصّة فاخرة للطباعة', 'Graphic Premium Cut', 'graphic-premium', 28.0, 35.0, 1)"
    );

    $basicId = (int) $pdo->query("SELECT id FROM garments WHERE `key` = 'premium-basic-cut'")->fetchColumn();
    $graphicId = (int) $pdo->query("SELECT id FROM garments WHERE `key` = 'graphic-premium-cut'")->fetchColumn();

    $report('garment types created: premium-basic-cut=' . $basicId . ', graphic-premium-cut=' . $graphicId);

    /*
     * Both types start offering the whole palette and the whole size chart.
     *
     * The alternative -- seeding each type with only what products 1 and 2
     * happen to use today -- would silently narrow the shop: a colour the owner
     * had enabled on a product but not yet sold would become unofferable. An
     * owner removing colours from a type is one screen and one save; an owner
     * discovering a colour has vanished is a support call.
     */
    $pdo->exec(
        'INSERT INTO garment_colors (garment_id, color_id, sort_order)
         SELECT g.id, c.id, c.sort_order FROM garments g CROSS JOIN colors c'
    );
    $pdo->exec(
        'INSERT INTO garment_sizes (garment_id, size_id, sort_order)
         SELECT g.id, s.id, s.sort_order FROM garments g CROSS JOIN sizes s'
    );

    $report('each type seeded with '
        . (int) $pdo->query('SELECT COUNT(*) FROM colors')->fetchColumn() . ' colours and '
        . (int) $pdo->query('SELECT COUNT(*) FROM sizes')->fetchColumn() . ' sizes');

    /* --------------------------------------------------- the product columns */

    $pdo->exec(
        "ALTER TABLE products
            ADD COLUMN garment_id     SMALLINT UNSIGNED NULL AFTER garment,
            -- Artwork printed on this product. Its presence IS the distinction
            -- between a plain piece and a printed one: there is no type column
            -- saying which, because two fields that must agree eventually do
            -- not.
            ADD COLUMN artwork_path   VARCHAR(255) NULL AFTER garment_id,
            ADD COLUMN artwork_x      TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER artwork_path,
            ADD COLUMN artwork_y      TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER artwork_x,
            ADD COLUMN artwork_scale  SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER artwork_y,
            ADD COLUMN artwork_side   VARCHAR(10) NOT NULL DEFAULT 'front' AFTER artwork_scale,
            ADD CONSTRAINT products_garment_fk FOREIGN KEY (garment_id) REFERENCES garments (id)"
    );

    $pdo->exec(
        'CREATE TABLE product_colors (
            product_id  INT UNSIGNED NOT NULL,
            color_id    SMALLINT UNSIGNED NOT NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (product_id, color_id),
            INDEX product_colors_color (color_id),
            CONSTRAINT product_colors_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
            CONSTRAINT product_colors_color_fk   FOREIGN KEY (color_id)   REFERENCES colors (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    /* ------------------------------------------------------------- backfill */

    /*
     * Every existing product is a t-shirt, so every one becomes the basic cut.
     * Anything whose old `garment` value is not 'tshirt' is REPORTED rather
     * than assigned: a product pointing at a garment nobody has defined is a
     * fact worth seeing, not a row to guess at.
     */
    $stmt = $pdo->prepare('UPDATE products SET garment_id = ? WHERE garment = ?');
    $stmt->execute([$basicId, 'tshirt']);
    $report('products assigned to premium-basic-cut: ' . $stmt->rowCount());

    $unmapped = $pdo->query(
        "SELECT id, slug, garment FROM products WHERE garment_id IS NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($unmapped === []) {
        $report('products with no garment type: 0');
    } else {
        $report('PRODUCTS LEFT WITHOUT A TYPE (their garment value matches no row):');

        foreach ($unmapped as $row) {
            $report('  #' . $row['id'] . ' ' . $row['slug'] . ' -> garment="' . $row['garment'] . '"');
        }
    }

    /*
     * A product's colours are the ones its ACTIVE variants are in. Reading the
     * inactive ones too would re-offer a colour the owner switched off, which
     * is the same mistake migration 007 exists to prevent.
     */
    $pdo->exec(
        'INSERT INTO product_colors (product_id, color_id, sort_order)
         SELECT DISTINCT v.product_id, v.color_id, c.sort_order
           FROM product_variants v
           JOIN colors c ON c.id = v.color_id
          WHERE v.is_active = 1'
    );

    $report('product_colors backfilled: '
        . (int) $pdo->query('SELECT COUNT(*) FROM product_colors')->fetchColumn() . ' rows across '
        . (int) $pdo->query('SELECT COUNT(DISTINCT product_id) FROM product_colors')->fetchColumn()
        . ' product(s)');

    /*
     * A last look for the thing that would bite later: a variant whose colour
     * is not offered by its product's type. None can exist today -- both types
     * were seeded with the whole palette -- but the check costs nothing and
     * says so out loud rather than being assumed.
     */
    $stray = (int) $pdo->query(
        'SELECT COUNT(*) FROM product_variants v
           JOIN products p ON p.id = v.product_id
          WHERE p.garment_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM garment_colors gc
                             WHERE gc.garment_id = p.garment_id AND gc.color_id = v.color_id)'
    )->fetchColumn();

    $report('variants in a colour their type does not offer: ' . $stray . ' (expected 0)');
};
