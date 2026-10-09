<?php

declare(strict_types=1);

/*
 * Why a variant is switched off, not merely that it is.
 *
 * Two different things set is_active = 0, and they must not undo each other.
 * A shop owner turns one colour off because the cloth ran out. Later the whole
 * product is taken off the shop for a season. When it comes back, switching
 * every variant on again would quietly put the colour with no cloth back on
 * sale -- and the first order for it could not be filled.
 *
 * So a product-level deactivation marks the rows it switched off, and a
 * reactivation raises only those. A variant an owner turned off by hand carries
 * no mark and stays off until the owner says otherwise.
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        'ALTER TABLE product_variants
            ADD COLUMN deactivated_by_product TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
    );
};
