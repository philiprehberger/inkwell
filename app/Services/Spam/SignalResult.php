<?php

namespace App\Services\Spam;

/**
 * A single signal's contribution to the spam score.
 *
 * - `name` becomes the key in the spam_signals JSON breakdown.
 * - `points` is in [0, 100]. >= 100 means hard-block (short-circuits the
 *   pipeline; decision = rejected).
 * - `metadata` is rendered verbatim in the admin's per-submission view —
 *   this is the "explainability" pitch. Buyers see *why* something scored.
 */
final readonly class SignalResult
{
    public function __construct(
        public string $name,
        public int $points,
        public array $metadata = [],
    ) {}

    public function isHardBlock(): bool
    {
        return $this->points >= 100;
    }
}
