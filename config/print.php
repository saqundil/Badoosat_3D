<?php

declare(strict_types=1);

use App\Support\Config;

return [
    /*
     * auto      pick the first driver whose isAvailable() returns true, in the
     *           order listed below
     * imagick   the PHP extension: a linked library, needs no exec, and is
     *           present on most shared hosts
     * shell     proc_open to ImageMagick and Ghostscript, for a VPS
     * passthrough  300 DPI RGB PNG, colour conversion left to the print house
     *
     * Moving to a VPS is this one line.
     */
    'driver' => Config::env('PRINT_DRIVER') ?? 'auto',

    'order' => ['imagick', 'shell', 'passthrough'],

    'dpi' => [
        'target' => 300,
        'warn'   => 150,
        'reject' => 100,
    ],

    'bleed_mm'      => 3,
    'crop_mark_mm'  => 5,

    // Leave null to skip the CMYK step; a driver that cannot convert says so.
    'icc_profile' => null,

    'binaries' => [
        'magick'     => Config::env('MAGICK_BIN') ?? 'magick',
        'ghostscript' => Config::env('GS_BIN') ?? 'gs',
    ],

    'timeout_seconds' => 60,
];
