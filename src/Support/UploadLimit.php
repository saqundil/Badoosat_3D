<?php

declare(strict_types=1);

namespace App\Support;

final class UploadLimit
{
    /** @return array{upload_max_filesize:int,post_max_size:int,application:int} */
    public static function sources(): array
    {
        return [
            'upload_max_filesize' => self::iniBytes((string) ini_get('upload_max_filesize')),
            'post_max_size' => self::iniBytes((string) ini_get('post_max_size')),
            'application' => (int) Config::get('app.uploads.max_bytes'),
        ];
    }

    public static function bytes(): int
    {
        $limits = array_filter(self::sources(), static fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? 0 : min($limits);
    }

    public static function mebibytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 2, '.', ''), '0'), '.');
    }

    public static function message(): string
    {
        return 'الحد الفعلي للرفع ' . self::mebibytes(self::bytes()) . ' ميغابايت.';
    }

    public static function postBodyExceeded(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || $_POST !== [] || $_FILES !== []) {
            return false;
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMax = self::sources()['post_max_size'];

        return $postMax > 0 && $length > $postMax;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?$/i', $value, $matches)) {
            return max(0, (int) $value);
        }

        $bytes = (float) $matches[1];
        $power = array_search(strtoupper($matches[2]), ['', 'K', 'M', 'G', 'T', 'P'], true);

        return (int) floor($bytes * (1024 ** (int) $power));
    }
}
