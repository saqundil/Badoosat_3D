<?php

declare(strict_types=1);

namespace App\Print;

/**
 * The floor: always available, needs no extension and no binary.
 *
 * Hands the artwork through in RGB at 300 DPI and leaves colour conversion to
 * the press, which many presses prefer anyway.
 */
final class PassthroughDriver extends AbstractPrintDriver
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function convertsColour(): bool
    {
        return false;
    }

    public function toCmyk(string $src, string $dst, string $iccPath): void
    {
        if (!is_file($src)) {
            throw new PrintDriverException('Artwork missing: ' . $src);
        }

        if (realpath($src) === realpath($dst)) {
            return;
        }

        if (!copy($src, $dst)) {
            throw new PrintDriverException('Could not copy artwork to ' . $dst);
        }
    }
}
