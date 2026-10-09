<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The registry of models a garment type may be shown on.
 *
 * Exists for the same reason HeroModels does: so that a .glb path is never a
 * value an administrator types or uploads. A type stores a key; everything
 * downstream asks here for the file. A key that is not in config is not a
 * model, and pathFor() returns null rather than a guess.
 */
final class GarmentModels
{
    /** @return array<string,array{label:string,file:string,geometry:string,shared_model?:bool}> */
    public static function all(): array
    {
        $models = Config::file('garment-models');

        return is_array($models) ? $models : [];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function defaultKey(): string
    {
        return (string) (array_key_first(self::all()) ?? '');
    }

    /** The .glb this key names, or null when the key is not in the registry. */
    public static function pathFor(string $key): ?string
    {
        $file = self::all()[$key]['file'] ?? null;

        return is_string($file) && $file !== '' ? $file : null;
    }

    /** The model's name in the current language: label_<locale>, else label (Arabic). */
    public static function labelFor(string $key): string
    {
        $model = self::all()[$key] ?? [];

        return (string) ($model['label_' . Lang::locale()] ?? $model['label'] ?? $key);
    }

    /**
     * The config/garments.php entry holding this model's print geometry.
     *
     * Named separately from the model key because a model and its measured
     * UV rectangles are two facts, not one: several cuts may share a mesh, and
     * a new mesh has no geometry until someone measures it.
     */
    public static function geometryFor(string $key): string
    {
        return (string) (self::all()[$key]['geometry'] ?? 'tshirt');
    }

    /**
     * Whether this entry is standing in on a mesh that is not its own.
     *
     * Both garment types currently point at tshirt.glb; see the note at the top
     * of config/garment-models.php. The admin screen reads this so the state is
     * visible on the page rather than buried in a comment nobody opens.
     */
    public static function isShared(string $key): bool
    {
        return !empty(self::all()[$key]['shared_model']);
    }

    /** Every key currently standing in on someone else's mesh. @return array<int,string> */
    public static function sharedKeys(): array
    {
        return array_values(array_filter(self::keys(), static fn (string $key): bool => self::isShared($key)));
    }
}
