<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fixed window per action per client, held in files under storage/cache so it
 * works on shared hosting with several PHP processes.
 */
final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    public static function make(): self
    {
        return new self(Config::get('app.paths.cache') . '/ratelimit');
    }

    /** @return bool true when the caller is still inside its allowance */
    public function attempt(string $action, string $client, int $limit, int $seconds): bool
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return true;
        }

        $path   = $this->directory . '/' . hash('sha256', $action . '|' . $client) . '.json';
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            return true;
        }

        try {
            flock($handle, LOCK_EX);

            $raw    = stream_get_contents($handle) ?: '';
            $window = json_decode($raw, true);
            $now    = time();

            if (!is_array($window) || ($window['reset'] ?? 0) <= $now) {
                $window = ['count' => 0, 'reset' => $now + $seconds];
            }

            $window['count']++;
            $allowed = $window['count'] <= $limit;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($window));
            fflush($handle);

            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Applies a limit named in config/app.php and returns whether to proceed. */
    public function check(string $action, string $client): bool
    {
        $limits = Config::get('app.rate_limits.' . $action);

        if (!is_array($limits)) {
            return true;
        }

        return $this->attempt($action, $client, (int) $limits[0], (int) $limits[1]);
    }

    public static function client(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
}
