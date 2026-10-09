<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "ALTER TABLE products
            ADD COLUMN presentation_type VARCHAR(40) NOT NULL DEFAULT 'standard' AFTER garment,
            ADD COLUMN show_3d TINYINT(1) NOT NULL DEFAULT 1 AFTER presentation_type,
            ADD COLUMN customization_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_3d"
    );

    $pdo->exec(
        "ALTER TABLE product_images
            ADD COLUMN image_type VARCHAR(40) NOT NULL DEFAULT 'product' AFTER path,
            ADD COLUMN primary_for INT UNSIGNED NULL AFTER image_type,
            ADD COLUMN mime_type VARCHAR(100) NULL AFTER height_px,
            ADD COLUMN file_size INT UNSIGNED NULL AFTER mime_type,
            ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sort_order"
    );

    $pdo->exec(
        "INSERT INTO product_images
            (product_id, color_id, path, image_type, primary_for, width_px, height_px,
             mime_type, file_size, sort_order, created_at)
         SELECT p.id, NULL, p.image_path, 'product', NULL, 0, 0, NULL, NULL, 0, NOW()
           FROM products p
          WHERE p.image_path IS NOT NULL
            AND p.image_path <> ''
            AND NOT EXISTS (
                SELECT 1
                  FROM product_images pi
                 WHERE pi.product_id = p.id
                   AND pi.path = p.image_path
            )"
    );

    $pdo->exec(
        "UPDATE product_images pi
         JOIN (
             SELECT p.id AS product_id, MIN(candidate.id) AS image_id
               FROM products p
               JOIN product_images candidate
                 ON candidate.product_id = p.id
                AND candidate.path = p.image_path
              WHERE p.image_path IS NOT NULL
                AND p.image_path <> ''
              GROUP BY p.id
         ) selected ON selected.image_id = pi.id
            SET pi.primary_for = selected.product_id"
    );

    $pdo->exec(
        'ALTER TABLE product_images
            ADD UNIQUE KEY product_images_one_primary (primary_for)'
    );

    $pdo->exec('ALTER TABLE products DROP COLUMN model_path');
};
