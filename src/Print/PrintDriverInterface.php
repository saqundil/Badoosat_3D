<?php

declare(strict_types=1);

namespace App\Print;

interface PrintDriverInterface
{
    public function isAvailable(): bool;

    /**
     * Converts to CMYK using the given ICC profile.
     *
     * A driver that cannot convert must still produce $dst, and must say so
     * through convertsColour() so the service can label the output honestly.
     */
    public function toCmyk(string $src, string $dst, string $iccPath): void;

    public function toPdf(string $src, string $dst, float $wCm, float $hCm, float $bleedMm): void;

    /*
     * The two below are not part of the processing contract, but the service
     * has to record which driver produced a file and whether the colour in it
     * is real CMYK or RGB awaiting conversion at the press. Without them the
     * service would have to type-check concrete drivers to tell.
     */

    public function convertsColour(): bool;

    public function name(): string;
}
