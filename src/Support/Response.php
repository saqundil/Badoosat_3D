<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Escapes for HTML text and attribute contexts. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Safe inside a <script type="application/json"> block: the HEX flags stop
     * the payload closing the tag or breaking out of an attribute.
     */
    public static function jsonForHtml(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, self::JSON_FLAGS);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['error' => $message] + $extra, $status);
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');

        if (trim($raw) === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::error('Malformed JSON body.', 400);
        }

        return is_array($data) ? $data : [];
    }
}
