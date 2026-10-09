<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Image;

/**
 * Writes the three widths every listing <img> asks for, beside their source.
 *
 *   php tools/make-images.php public/images/products/classic-tee.webp
 *   php tools/make-images.php --seed        (product and design artwork)
 *
 * Image::responsive() states the naming convention -- `<base>-400.webp` and so
 * on -- and falls back to the original when a derivative is missing, so a
 * listing never breaks because this was not run. It is run so a phone is not
 * sent a 1200px picture to draw at 170.
 *
 * GD, not Imagick: it is core on effectively every shared host and needs no
 * exec. WebP throughout, because the browsers that cannot read it cannot read
 * the site's stylesheet either.
 */

$public = dirname(__DIR__) . '/public';

/** @return array{0:int,1:int} */
function derive(string $source, int $width): array
{
    $image = @imagecreatefromstring((string) file_get_contents($source));

    if ($image === false) {
        fwrite(STDERR, "cannot read $source\n");
        exit(1);
    }

    $w = imagesx($image);
    $h = imagesy($image);

    // Never upscale: a 400px source blown up to 1200 is a larger file that
    // looks worse than the original.
    $target = min($width, $w);
    $height = (int) round($h * ($target / $w));

    $out = imagecreatetruecolor($target, $height);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $image, 0, 0, 0, 0, $target, $height, $w, $h);

    $path = Image::variant(str_replace('\\', '/', $source), $width);
    imagewebp($out, $path, 82);

    imagedestroy($out);
    imagedestroy($image);

    return [$target, $height];
}

function process(string $source): void
{
    if (!is_file($source)) {
        fwrite(STDERR, "missing: $source\n");

        return;
    }

    foreach (Image::WIDTHS as $width) {
        [$w, $h] = derive($source, $width);
        printf("  %-52s %4d x %4d\n", basename(Image::variant($source, $width)), $w, $h);
    }
}

/* ------------------------------------------------------------------ seed */

/**
 * Stand-in artwork so the shop has something to sell before a photographer and
 * an illustrator have been near it. Every one is drawn here rather than
 * committed as a binary, so the repository stays text and the seed can be
 * regenerated at any size.
 */
function seed(string $public): void
{
    /* ---- product photography: the rendered shirt, tinted per product ---- */

    $source = $public . '/images/hero-poster.webp';

    if (!is_file($source)) {
        fwrite(STDERR, "hero poster missing; run tools/render-poster.mjs first\n");
        exit(1);
    }

    @mkdir($public . '/images/products', 0775, true);
    @mkdir($public . '/images/designs', 0775, true);
    @mkdir($public . '/uploads/designs/catalog', 0775, true);

    foreach ([
        'classic-tee'  => [1.00, 1.00, 1.00],
        'oversize-tee' => [0.97, 0.96, 0.93],
    ] as $slug => $tint) {
        $image = imagecreatefromstring((string) file_get_contents($source));
        $w     = imagesx($image);
        $h     = imagesy($image);

        // Square, centre-cropped: a grid of cards with one landscape picture in
        // it is a grid with a hole in it.
        $side  = min($w, $h);
        $out   = imagecreatetruecolor($side, $side);
        imagecopyresampled($out, $image, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2),
            $side, $side, $side, $side);

        if ($tint !== [1.0, 1.0, 1.0]) {
            imagefilter($out, IMG_FILTER_COLORIZE,
                (int) (($tint[0] - 1) * 60), (int) (($tint[1] - 1) * 60), (int) (($tint[2] - 1) * 60));
        }

        $path = $public . '/images/products/' . $slug . '.webp';
        imagewebp($out, $path, 86);
        imagedestroy($out);
        imagedestroy($image);

        echo $slug . "\n";
        process($path);
    }

    /* ------------------------- ready-made designs: press file and thumb --- */

    $designs = [
        'amman-skyline' => ['أفق عمّان',  [24, 24, 26]],
        'kufic-salam'   => ['سلام',       [18, 40, 70]],
        'olive-branch'  => ['غصن زيتون',  [58, 74, 42]],
    ];

    foreach ($designs as $slug => [, $rgb]) {
        // The press master: transparent, at the export size the garment config
        // asks for, so it goes through the same pipeline as a customer's file.
        $print = imagecreatetruecolor(3307, 4134);
        imagealphablending($print, false);
        imagesavealpha($print, true);
        imagefill($print, 0, 0, imagecolorallocatealpha($print, 0, 0, 0, 127));
        imagealphablending($print, true);

        $ink = imagecolorallocate($print, $rgb[0], $rgb[1], $rgb[2]);
        draw($print, $ink, $slug, 3307, 4134);

        imagepng($print, $public . '/uploads/designs/catalog/' . $slug . '.png');
        imagedestroy($print);

        // The thumbnail the product page shows, on paper rather than nothing.
        $thumb = imagecreatetruecolor(600, 600);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 237, 235, 230));
        draw($thumb, imagecolorallocate($thumb, $rgb[0], $rgb[1], $rgb[2]), $slug, 600, 600);

        $path = $public . '/images/designs/' . $slug . '.webp';
        imagewebp($thumb, $path, 88);
        imagedestroy($thumb);

        echo $slug . "\n";
        process($path);
    }
}

/** Three recognisable marks, drawn with primitives so no font file is needed. */
function draw(\GdImage $image, int $ink, string $which, int $w, int $h): void
{
    $unit = (int) ($w / 20);

    if ($which === 'amman-skyline') {
        // A skyline: blocks of varying height on a baseline.
        $base = (int) ($h * 0.62);

        foreach ([5, 9, 7, 12, 8, 14, 6, 10] as $i => $tall) {
            $x = (int) ($w * 0.18) + $i * (int) ($unit * 1.6);
            imagefilledrectangle($image, $x, $base - (int) ($tall * $unit / 2), $x + $unit, $base, $ink);
        }

        imagefilledrectangle($image, (int) ($w * 0.15), $base, (int) ($w * 0.85), $base + (int) ($unit / 4), $ink);

        return;
    }

    if ($which === 'kufic-salam') {
        // A square kufic knot: nested open rectangles.
        $size = (int) ($w * 0.5);
        $x    = (int) (($w - $size) / 2);
        $y    = (int) (($h - $size) / 2);

        for ($ring = 0; $ring < 3; $ring++) {
            $inset = $ring * (int) ($unit * 1.6);
            $t     = (int) ($unit * 0.55);

            imagefilledrectangle($image, $x + $inset, $y + $inset, $x + $size - $inset, $y + $inset + $t, $ink);
            imagefilledrectangle($image, $x + $inset, $y + $size - $inset - $t, $x + $size - $inset, $y + $size - $inset, $ink);
            imagefilledrectangle($image, $x + $inset, $y + $inset, $x + $inset + $t, $y + $size - $inset, $ink);
        }

        return;
    }

    // An olive branch: a stem with leaves either side.
    $cx = (int) ($w / 2);
    $t  = (int) ($unit * 0.35);

    imagefilledrectangle($image, $cx - $t, (int) ($h * 0.28), $cx + $t, (int) ($h * 0.72), $ink);

    for ($i = 0; $i < 6; $i++) {
        $y  = (int) ($h * 0.32) + $i * (int) ($h * 0.07);
        $rx = (int) ($unit * 1.5);
        $ry = (int) ($unit * 0.7);

        imagefilledellipse($image, $cx - $rx, $y, $rx * 2, $ry * 2, $ink);
        imagefilledellipse($image, $cx + $rx, $y + (int) ($h * 0.035), $rx * 2, $ry * 2, $ink);
    }
}

/* ------------------------------------------------------------------ main */

$args = array_slice($argv, 1);

if ($args === [] || $args[0] === '--seed') {
    seed($public);
    echo "\ndone\n";
    exit(0);
}

foreach ($args as $source) {
    echo $source . "\n";
    process($source);
}
