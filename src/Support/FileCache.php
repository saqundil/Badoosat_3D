<?php

declare(strict_types=1);

namespace App\Support;

final class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $defaultTtl = 3600,
    ) {
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            $entry = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($entry) || !isset($entry['expires'])) {
            return null;
        }

        if ($entry['expires'] < time()) {
            $this->forget($key);

            return null;
        }

        return $entry['value'];
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $path = $this->path($key);

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $payload = json_encode(
            ['expires' => time() + ($ttl ?? $this->defaultTtl), 'value' => $value],
            JSON_UNESCAPED_UNICODE
        );

        // Write then rename: a reader never sees a half-written file.
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temp, $payload, LOCK_EX) !== false) {
            rename($temp, $path);
        }
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function remember(string $key, callable $producer, ?int $ttl = null): mixed
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        $this->put($key, $value, $ttl);

        return $value;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
    }
}
