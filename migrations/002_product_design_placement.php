<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "ALTER TABLE products
            ADD COLUMN design_x TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER presentation_type,
            ADD COLUMN design_y TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER design_x,
            ADD COLUMN design_scale SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER design_y"
    );
};