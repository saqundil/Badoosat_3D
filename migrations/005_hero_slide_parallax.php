<?php

declare(strict_types=1);

/*
 * Whether a slide answers the pointer.
 *
 * The hero drifts its layers against mouse movement, and the garment tilts
 * with them. That suits a product shot; it does not always suit a slide whose
 * background is a photograph with a horizon in it, where the drift reads as
 * the picture coming loose.
 *
 * Default 1, so every slide that exists keeps the motion it has and nothing
 * changes on screen after this runs. A reduced-motion preference still wins
 * over the column either way -- that is decided in hero.js, not here.
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        'ALTER TABLE hero_slides
            ADD COLUMN parallax_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_badges'
    );
};
