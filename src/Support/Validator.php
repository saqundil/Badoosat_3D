<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Collects field => message pairs. Knows nothing about requests or responses:
 * services hand it an array and read the errors back.
 */
final class Validator
{
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /** Shipped assets and uploads only: no traversal, no absolute URLs. */
    private const ASSET_PATH = '#^/(?:models|images|uploads)/[A-Za-z0-9._/-]+$#';

    /** @var array<string,string> */
    private array $errors = [];

    public function __construct(private readonly array $input)
    {
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function value(string $field, mixed $default = null): mixed
    {
        return $this->input[$field] ?? $default;
    }

    public function string(string $field, mixed $default = ''): string
    {
        return trim((string) ($this->input[$field] ?? $default));
    }

    public function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;

        return $this;
    }

    public function required(string $field, string $label): self
    {
        if ($this->string($field) === '') {
            $this->fail($field, $label . ' مطلوب.');
        }

        return $this;
    }

    public function maxLength(string $field, int $max, string $label): self
    {
        if (mb_strlen($this->string($field)) > $max) {
            $this->fail($field, $label . ' يجب ألا يتجاوز ' . $max . ' حرفًا.');
        }

        return $this;
    }

    public function hex(string $field, string $label): self
    {
        if (!preg_match(self::HEX, $this->string($field))) {
            $this->fail($field, $label . ' يجب أن يكون بصيغة ‎#RRGGBB.');
        }

        return $this;
    }

    /** @param string[] $allowed */
    public function in(string $field, array $allowed, string $label): self
    {
        if (!in_array($this->value($field), $allowed, true)) {
            $this->fail($field, $label . ' قيمة غير معروفة.');
        }

        return $this;
    }

    public function integer(string $field, int $min, int $max, string $label): self
    {
        $value = $this->value($field);

        if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            $this->fail($field, $label . ' يجب أن يكون رقمًا بين ' . $min . ' و ' . $max . '.');
        }

        return $this;
    }

    public function decimal(string $field, float $min, float $max, string $label): self
    {
        $value = $this->value($field);

        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            $this->fail($field, $label . ' يجب أن يكون بين ' . $min . ' و ' . $max . '.');
        }

        return $this;
    }

    public function assetPath(string $field, string $label, string $extension = '', bool $optional = false): self
    {
        $value = $this->string($field);

        if ($value === '') {
            if (!$optional) {
                $this->fail($field, $label . ' مطلوب.');
            }

            return $this;
        }

        if (!self::isAssetPath($value, $extension)) {
            $this->fail($field, $label . ' مسار غير مسموح.');
        }

        return $this;
    }

    public function link(string $field, string $label, bool $optional = true): self
    {
        $value = $this->string($field);

        if ($value === '') {
            if (!$optional) {
                $this->fail($field, $label . ' مطلوب.');
            }

            return $this;
        }

        if (!self::isLink($value)) {
            $this->fail($field, $label . ' يجب أن يكون رابطًا كاملًا أو مسارًا داخليًا يبدأ بـ ‎/.');
        }

        return $this;
    }

    /**
     * Decodes a JSON field and hands each element to a callback that returns an
     * error string, or null when the element is fine.
     */
    public function jsonList(string $field, string $label, int $max, callable $each): self
    {
        $raw = $this->value($field);

        if (is_string($raw)) {
            try {
                $list = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->fail($field, $label . ' ليست JSON صالحة.');
            }
        } else {
            $list = $raw;
        }

        if (!is_array($list) || !array_is_list($list)) {
            return $this->fail($field, $label . ' يجب أن تكون مصفوفة.');
        }

        if ($list === []) {
            return $this->fail($field, $label . ' لا يمكن أن تكون فارغة.');
        }

        if (count($list) > $max) {
            return $this->fail($field, $label . ' لا تقبل أكثر من ' . $max . ' عنصرًا.');
        }

        foreach ($list as $i => $item) {
            $message = $each(is_array($item) ? $item : [], $i + 1);

            if (is_string($message)) {
                return $this->fail($field, $message);
            }
        }

        return $this;
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match(self::HEX, $value);
    }

    public static function isAssetPath(mixed $value, string $extension = ''): bool
    {
        if (!is_string($value) || $value === '' || str_contains($value, '..')) {
            return false;
        }

        if ($extension !== '' && !str_ends_with(strtolower($value), $extension)) {
            return false;
        }

        return (bool) preg_match(self::ASSET_PATH, $value);
    }

    public static function isLink(string $value): bool
    {
        if (str_starts_with($value, '//')) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return !str_contains($value, '..');
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /** @return array<int,array<string,mixed>> */
    public static function decodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_is_list($raw) ? $raw : [];
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
