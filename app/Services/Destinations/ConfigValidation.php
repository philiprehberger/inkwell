<?php

namespace App\Services\Destinations;

final readonly class ConfigValidation
{
    public function __construct(
        public bool $valid,
        public array $errors = [],
    ) {}

    public static function ok(): self
    {
        return new self(valid: true);
    }

    public static function fail(array $errors): self
    {
        return new self(valid: false, errors: $errors);
    }
}
