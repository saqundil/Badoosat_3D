<?php

declare(strict_types=1);

/*
 * The models a garment type may be shown on.
 *
 * Same rule as config/hero-models.php: an administrator picks a key, never a
 * path, and there is no upload door. The only .glb files the product screen can
 * ever reach are the ones written here, by a programmer, in the repository.
 *
 * `geometry` names the entry in config/garments.php that carries this model's
 * print rectangles -- the UV areas artwork is fitted into. The two keys are
 * written side by side rather than assumed to be the same word, because a
 * model and its measured geometry are separate facts: two cuts photographed
 * from the same mesh share geometry, and a new mesh needs its own measurement
 * before it can take a design at all.
 *
 * ---------------------------------------------------------------------------
 * DEFERRED, AND DELIBERATE: both garment types point at the same model.
 * ---------------------------------------------------------------------------
 *
 * premium-basic-cut and graphic-premium-cut are two different cuts in the
 * catalogue and two different rows in the `garments` table -- but until a
 * second mesh is measured they are drawn on ONE model, tshirt.glb. The
 * customer therefore sees the same shirt shape for both types. That is a
 * placeholder, not the finished product.
 *
 * Closing it needs three things, in order:
 *   1. basic_t-shirt.glb moved into public/models/ (it currently sits untracked
 *      at the project root and is served by nothing);
 *   2. tools/measure-print-area.mjs run against it to produce the u0/u1/v0/v1
 *      rectangles and the uv_scale_cm for its UV layout;
 *   3. a second entry in config/garments.php holding those, and the `geometry`
 *      key below pointed at it.
 *
 * None of that is guesswork that belongs in this file: a print rectangle that
 * was estimated rather than measured puts ink off the panel. Until step 2 has
 * actually been run, both keys below stay as they are.
 *
 * The admin garment screen prints this state on the page, so nobody has to read
 * this comment to discover it.
 *
 * `label` is the Arabic name; `label_en` (any `label_<locale>`) is used by
 * GarmentModels::labelFor() when the dashboard is in that language.
 */

return [
    'premium-basic' => [
        'label'    => 'قصّة أساسية فاخرة',
        'label_en' => 'Premium Basic Cut',
        'file'     => '/models/tshirt.glb',
        'geometry' => 'tshirt',
        // See the note above: awaiting its own measured mesh.
        'shared_model' => true,
    ],
    'graphic-premium' => [
        'label'    => 'قصّة فاخرة للطباعة',
        'label_en' => 'Graphic Premium Cut',
        'file'     => '/models/tshirt.glb',
        'geometry' => 'tshirt',
        // See the note above: awaiting its own measured mesh.
        'shared_model' => true,
    ],
];
