<?php

declare(strict_types=1);

namespace App\Print;

use Imagick;
use ImagickException;

/**
 * The preferred driver on shared hosting: a linked library, so it needs no
 * exec, no proc_open and no external binary.
 */
final class ImagickDriver extends AbstractPrintDriver
{
    public function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    public function convertsColour(): bool
    {
        return true;
    }

    public function toCmyk(string $src, string $dst, string $iccPath): void
    {
        if (!$this->isAvailable()) {
            throw new PrintDriverException('The imagick extension is not loaded.');
        }

        if (!is_file($src)) {
            throw new PrintDriverException('Artwork missing: ' . $src);
        }

        try {
            $image = new Imagick();
            $image->readImage($src);
            $image->setImageResolution(300, 300);
            $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

            // Flatten first: transparency has no meaning in CMYK.
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

            if ($iccPath !== '' && is_file($iccPath)) {
                $profile = file_get_contents($iccPath);

                if ($profile !== false) {
                    $image->profileImage('icc', $profile);
                }
            }

            $image->transformImageColorspace(Imagick::COLORSPACE_CMYK);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(92);
            $image->writeImage($dst);
            $image->clear();
        } catch (ImagickException $e) {
            throw new PrintDriverException('Imagick failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
