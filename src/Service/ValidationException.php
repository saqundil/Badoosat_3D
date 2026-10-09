<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Carries field => message pairs out of a service. The HTML layer flashes them
 * into a form; the JSON layer serialises them. Neither shape is decided here.
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors) ?: 'Validation failed.');
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
