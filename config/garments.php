<?php

declare(strict_types=1);

/*
 * Print geometry for public/models/tshirt.glb. Each rectangle contains the
 * continuous body panel for one side, from the shoulders through the chest to
 * the hem. Sleeves and collar are separate UV islands and stay unprinted.
 *
 * v runs UP the garment, so on a square canvas of side S the pixel rectangle is
 *   x = u0 * S            w = (u1 - u0) * S
 *   y = (1 - v1) * S      h = (v1 - v0) * S
 * with the CanvasTexture left at its default flipY, which maps v-up to
 * canvas-up. Any artwork is drawn with drawImage() into that pixel rectangle,
 * fitted in centimetres first, so a square design prints square.
 *
 * The configured rectangles correspond approximately to these 2048px atlas
 * regions: front x=5, y=680, w=935, h=1352; back x=1016, y=716,
 * w=921, h=1316.
 */

return ['tshirt' => [
    'model' => '/models/tshirt.glb',
    'customizable' => true,
    'print_area' => [
        'front' => ['u0' => 0.0022, 'u1' => 0.4587, 'v0' => 0.0077, 'v1' => 0.6681],
        'back'  => ['u0' => 0.4962, 'u1' => 0.9458, 'v0' => 0.0077, 'v1' => 0.6505],
    ],
    'uv_scale_cm'   => ['u' => 124.5, 'v' => 123.5],
    /*
     * The whole printable panel: shoulders to hem, seam to seam. It is the
     * limit of where ink CAN go, not the size anything is actually printed at.
     */
    'print_size_cm' => ['w' => 56, 'h' => 80],
    /*
     * How big a plain uploaded file is placed, unless the customer resizes it
     * in the editor. A conventional chest print.
     *
     * This exists because the resolution check used to measure an upload as
     * though it were being stretched across the entire panel above. That asked
     * 15.6 megapixels of a customer merely to avoid a warning, and 62.5 to be
     * called good -- neither of which fits through the upload limit, so every
     * file that could pass the check was too large to send.
     */
    'artwork_size_cm' => ['w' => 28, 'h' => 35],
    'canvas_px'     => 2048,
    'chest_rect_px' => ['x' => 5, 'y' => 680, 'w' => 935, 'h' => 1352],
], 'hoodie' => [
    'model'          => null,
    'customizable'   => false,
    'print_area'     => [],
    'uv_scale_cm'    => [],
    'print_size_cm'  => [],
    'artwork_size_cm' => [],
    'canvas_px'      => 0,
    'chest_rect_px'  => [],
]];
