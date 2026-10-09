<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The bundled model registry, read from config/hero-models.php.
 *
 * One place answers three questions -- is this key real, what file does it
 * name, and what does the administrator see in the dropdown -- so the editor's
 * list and the validator's allowed set cannot drift apart: they are the same
 * array.
 */
final class HeroModels
{
    public const DEFAULT_KEY = 'tshirt';

    /** @var array<string,array{label:string,file:string,garment:string}>|null */
    private static ?array $models = null;

    /** @return array<string,array{label:string,file:string,garment:string}> */
    public static function all(): array
    {
        return self::$models ??= Config::file('hero-models');
    }

    /** @return array<int,string> the keys, in the order the config lists them */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** The public URL of the .glb, or null when the key is not in the registry. */
    public static function file(string $key): ?string
    {
        return self::all()[$key]['file'] ?? null;
    }

    public static function label(string $key): string
    {
        return (string) (self::all()[$key]['label'] ?? $key);
    }

    /**
     * Print geometry for a model, from config/garments.php.
     *
     * Returns null when the key is unknown or the garment it names has no
     * geometry -- which is what a caller needs in order to decide that this
     * model cannot carry a design, rather than drawing artwork into an empty
     * rectangle.
     */
    public static function garment(string $key): ?array
    {
        $garment = self::all()[$key]['garment'] ?? null;

        if (!is_string($garment)) {
            return null;
        }

        $config = Config::file('garments')[$garment] ?? null;

        return is_array($config) && $config['print_area'] !== [] ? $config : null;
    }

    /** Only for tests, which swap the config file under it. */
    public static function flush(): void
    {
        self::$models = null;
    }
}
