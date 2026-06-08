<?php

namespace App\Services\Destinations;

final readonly class HealthResult
{
    public const HEALTHY = 'healthy';
    public const DEGRADED = 'degraded';
    public const FAILED = 'failed';

    public function __construct(
        public string $state,
        public ?string $detail = null,
    ) {}
}
