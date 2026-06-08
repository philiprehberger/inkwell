<?php

namespace App\Services\Destinations;

/**
 * Result of one delivery attempt. Persisted to delivery_attempts.
 */
final readonly class AttemptResult
{
    public function __construct(
        public bool $success,
        public string $requestSummary,
        public ?int $responseStatus = null,
        public ?string $responseBodySnippet = null,
        public ?int $latencyMs = null,
        public ?string $errorCode = null,
    ) {}

    public static function sent(string $summary, ?int $status = null, ?string $body = null, ?int $latencyMs = null): self
    {
        return new self(success: true, requestSummary: $summary, responseStatus: $status, responseBodySnippet: $body, latencyMs: $latencyMs);
    }

    public static function failed(string $summary, string $errorCode, ?int $status = null, ?string $body = null, ?int $latencyMs = null): self
    {
        return new self(success: false, requestSummary: $summary, responseStatus: $status, responseBodySnippet: $body, latencyMs: $latencyMs, errorCode: $errorCode);
    }
}
