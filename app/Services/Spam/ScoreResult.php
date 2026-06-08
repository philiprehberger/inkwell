<?php

namespace App\Services\Spam;

/**
 * What SpamScorer returns. Stored verbatim into the submission's
 * `spam_signals` JSON column (everything in here is rendered in the admin
 * breakdown view).
 */
final readonly class ScoreResult
{
    public function __construct(
        public string $state,
        public int $score,
        public int $threshold,
        public int $graduation_low,
        public ?string $hard_block,
        /** @var array<string, array<string, mixed>> */
        public array $signals,
        /** @var array<int, array{signal: string, points: int}> */
        public array $breakdown,
    ) {}

    public function toJson(): array
    {
        return [
            'decision' => $this->state,
            'total_score' => $this->score,
            'threshold' => $this->threshold,
            'graduation_low' => $this->graduation_low,
            'hard_block' => $this->hard_block,
            'signals' => $this->signals,
            'score_breakdown' => $this->breakdown,
        ];
    }
}
