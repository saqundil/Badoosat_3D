<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Config
{
    /** @var array<string,array<string,mixed>> */
    private static array $files = [];

    /** Config::get('print.dpi.warn') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file  = array_shift($parts);
        $value = self::file($file);

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public static function file(string $name): array
    {
        if (isset(self::$files[$name])) {
            return self::$files[$name];
        }

        $path = dirname(__DIR__, 2) . '/config/' . $name . '.php';

        if (!is_file($path)) {
            throw new RuntimeException('Unknown config file: ' . $name);
        }

        $values = require $path;

        // config/local.php is the shared-hosting escape hatch: plenty of hosts
        // give you no way to set an environment variable, but every one of them
        // lets you upload a file. It is git-ignored and overrides by key.
        $local = dirname(__DIR__, 2) . '/config/local.php';

        if (is_file($local)) {
            $overrides = require $local;

            if (is_array($overrides[$name] ?? null)) {
                $values = array_replace_recursive($values, $overrides[$name]);
            }
        }

        return self::$files[$name] = $values;
    }

    /**
     * An environment value, wherever the host chose to put it.
     *
     * getenv() alone is not enough: variables_order often omits E, and Apache's
     * SetEnv lands in $_SERVER rather than the process environment.
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)] as $value) {
            if ($value !== null && $value !== false && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /** Test seam: override a whole file in memory. */
    public static function set(string $name, array $values): void
    {
        self::$files[$name] = $values;
    }
}
