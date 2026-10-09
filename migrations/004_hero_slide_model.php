<?php

declare(strict_types=1);

/*
 * The hero slide gets a model of its own to describe.
 *
 * Until now a slide named a .glb by path, a colour by hex and a design by
 * image path -- three free-text columns that nothing could validate. It now
 * names a key in config/hero-models.php, a row in `colors` and a row in
 * `designs`, and it can say that it has no model at all.
 *
 * Nothing changes on screen after this runs. Every existing slide keeps the
 * model and the colour it already had: has_model = 1, model_key = the entry
 * whose file matches model_path, color_id = the colour whose hex matches
 * garment_color, show_badges = 1.
 *
 * Where a value cannot be matched this migration leaves the new column NULL
 * and SAYS SO on stdout rather than picking something. `garment_color` and
 * `design_image_path` are both left in place: the service still falls back to
 * them, so a slide whose design was a one-off upload keeps printing exactly
 * what it printed before.
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        "ALTER TABLE hero_slides
            ADD COLUMN has_model    TINYINT(1)        NOT NULL DEFAULT 1 AFTER id,
            ADD COLUMN model_key    VARCHAR(40)       NOT NULL DEFAULT 'tshirt' AFTER has_model,
            ADD COLUMN color_id     SMALLINT UNSIGNED NULL     AFTER model_key,
            ADD COLUMN design_id    INT UNSIGNED      NULL     AFTER color_id,
            -- The cart's vocabulary. Kept as VARCHAR rather than a second ENUM
            -- so the allowed set lives in CartService::PLACEMENTS alone.
            ADD COLUMN placement    VARCHAR(10)       NOT NULL DEFAULT 'front' AFTER design_id,
            ADD COLUMN show_badges  TINYINT(1)        NOT NULL DEFAULT 1 AFTER placement"
    );

    /*
     * ON DELETE SET NULL, not CASCADE: deleting a colour from the palette must
     * not silently delete a hero slide. The slide loses its colour and the
     * editor asks for a new one.
     */
    $pdo->exec(
        'ALTER TABLE hero_slides
            ADD CONSTRAINT hero_slides_color_fk  FOREIGN KEY (color_id)  REFERENCES colors (id)  ON DELETE SET NULL,
            ADD CONSTRAINT hero_slides_design_fk FOREIGN KEY (design_id) REFERENCES designs (id) ON DELETE SET NULL'
    );

    $say = static function (string $line): void {
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, '  ' . $line . "\n");
        }
    };

    /* ------------------------------------------------- the model each slide had */

    $models = require dirname(__DIR__) . '/config/hero-models.php';
    $byFile = [];

    foreach ($models as $key => $model) {
        $byFile[(string) $model['file']] = (string) $key;
    }

    foreach ($pdo->query('SELECT id, model_path FROM hero_slides')->fetchAll() as $row) {
        $key = $byFile[(string) $row['model_path']] ?? null;

        if ($key === null) {
            $say('slide ' . $row['id'] . ': model_path "' . $row['model_path']
                . '" is not in the registry -- left on the default key, check it in the editor.');

            continue;
        }

        $stmt = $pdo->prepare('UPDATE hero_slides SET model_key = ? WHERE id = ?');
        $stmt->execute([$key, $row['id']]);
    }

    /* --------------------------------------------------- colour, matched by hex */

    $pdo->exec(
        'UPDATE hero_slides s
            JOIN colors c ON c.hex = UPPER(s.garment_color)
            SET s.color_id = c.id'
    );

    $orphans = $pdo->query(
        'SELECT id, garment_color FROM hero_slides WHERE color_id IS NULL'
    )->fetchAll();

    foreach ($orphans as $row) {
        $say('slide ' . $row['id'] . ': garment_color ' . $row['garment_color']
            . ' matches no row in `colors` -- color_id left NULL, the old hex still paints it.');
    }

    /* ------------------------------------- design, matched by the printed image */

    $pdo->exec(
        'UPDATE hero_slides s
            JOIN designs d ON d.print_path = s.design_image_path
            SET s.design_id = d.id, s.placement = \'front\'
          WHERE s.design_image_path IS NOT NULL'
    );

    $unmatched = $pdo->query(
        'SELECT id, design_image_path FROM hero_slides
          WHERE design_image_path IS NOT NULL AND design_id IS NULL'
    )->fetchAll();

    foreach ($unmatched as $row) {
        $say('slide ' . $row['id'] . ': design_image_path "' . $row['design_image_path']
            . '" is not a row in `designs` -- design_id left NULL and the image kept as it was.');
    }
};
