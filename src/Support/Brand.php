<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The site's name and mark, in one place.
 *
 * The logo appears in the hero's navigation notch, in the top bar of every
 * other page and in the footer. Three copies of a path and a pair of
 * dimensions is three chances for one of them to go stale, so all of it is
 * read from here.
 *
 * The <img> is always written with an explicit width and height. Without them
 * the header has no height until the file arrives and everything under it
 * jumps once it does -- which on the home page is the hero, the largest thing
 * on the screen.
 */
final class Brand
{
    /** The name, as it is shown to a visitor. */
    public const NAME = 'BADOOSAT';

    /** Public URL of the wordmark. */
    public const LOGO = '/assets/img/badoosat_en_logo_name.png';

    /** Rendered height in CSS pixels; the width follows from the file's ratio. */
    public const HEIGHT = 34;

    /**
     * The height in the hero's navigation notch, which is taller than a top
     * bar and carries a larger mark. Paired with .brand--hero in hero.css:
     * this writes the element's width and height, that rule draws it.
     */
    public const HERO_HEIGHT = 44;

    /** Fallback ratio, used only to reserve a box when the file is missing. */
    private const FALLBACK_RATIO = 4.0;

    /** @var array{0:int,1:int}|null */
    private static ?array $size = null;

    public static function logoExists(): bool
    {
        return is_file(self::absolute());
    }

    /**
     * The intrinsic pixel size of the logo file, measured once per request.
     *
     * @return array{0:int,1:int}|null null when the file is not there
     */
    public static function size(): ?array
    {
        if (self::$size !== null) {
            return self::$size;
        }

        $path = self::absolute();

        if (!is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        self::$size = [(int) $info[0], (int) $info[1]];

        return self::$size;
    }

    /**
     * The width the logo occupies at self::HEIGHT, rounded to a whole pixel.
     *
     * Measured from the file so the reserved box is the box the image actually
     * fills. With no file there is nothing to measure and the ratio above keeps
     * the layout from collapsing to zero.
     */
    public static function width(int $height = self::HEIGHT): int
    {
        $size = self::size();

        if ($size === null || $size[1] === 0) {
            return (int) round($height * self::FALLBACK_RATIO);
        }

        return (int) round($height * ($size[0] / $size[1]));
    }

    private static function absolute(): string
    {
        return (string) Config::get('app.paths.public') . self::LOGO;
    }
}
