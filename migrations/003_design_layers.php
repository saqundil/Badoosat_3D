<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE design_layers (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            design_id   INT UNSIGNED NOT NULL,
            image_path  VARCHAR(255) NOT NULL,
            side        VARCHAR(10) NOT NULL DEFAULT 'front',
            position_x  TINYINT UNSIGNED NOT NULL DEFAULT 50,
            position_y  TINYINT UNSIGNED NOT NULL DEFAULT 50,
            scale_pct   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            INDEX design_layers_design (design_id, sort_order),
            CONSTRAINT design_layers_design_fk FOREIGN KEY (design_id)
                REFERENCES designs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "INSERT INTO design_layers
            (design_id, image_path, side, position_x, position_y, scale_pct, sort_order)
         SELECT id, print_path, 'front', 50, 50, 100, 0 FROM designs"
    );
};