<?php

namespace App\Services\Spam;

/**
 * Composes the SpamSignal pipeline and produces a single scoring decision.
 *
 * Decision rule:
 *   - Any hard-block (points >= 100) → state = rejected, score = 100.
 *   - Total score >= form.spam_threshold → state = spam.
 *   - Total score in [graduation_band] → state = quarantined.
 *   - Otherwise → state = clean.
 *
 * The graduation band defaults to (threshold - 20, threshold). Configurable
 * per workspace in v2.
 */
final class SpamScorer
{
    /** @var iterable<SpamSignal> */
    private iterable $signals;

    public function __construct(iterable $signals)
    {
        $this->signals = $signals;
    }

    public static function fromConfig(): self
    {
        $classes = config('inkwell.spam_signals', []);
        $instances = array_map(static fn (string $c) => app($c), $classes);
        return new self($instances);
    }

    public function score(SubmissionContext $ctx): ScoreResult
    {
        $breakdown = [];
        $signalsMeta = [];
        $total = 0;
        $hardBlock = null;

        foreach ($this->signals as $signal) {
            $result = $signal->evaluate($ctx);
            if ($result === null) {
                continue;
            }
            $signalsMeta[$result->name] = $result->metadata;
            $breakdown[] = ['signal' => $result->name, 'points' => $result->points];

            if ($result->isHardBlock()) {
                $hardBlock = $result;
                break;
            }
            $total += $result->points;
        }

        $threshold = (int) $ctx->form->spam_threshold;
        $graduationLow = max(0, $threshold - 20);

        if ($hardBlock !== null) {
            $state = SubmissionState::REJECTED;
            $total = 100;
        } elseif ($total >= $threshold) {
            $state = SubmissionState::SPAM;
        } elseif ($total >= $graduationLow && $total > 0) {
            $state = SubmissionState::QUARANTINED;
        } else {
            $state = SubmissionState::CLEAN;
        }

        $total = min(100, $total);

        return new ScoreResult(
            state: $state,
            score: $total,
            threshold: $threshold,
            graduation_low: $graduationLow,
            hard_block: $hardBlock?->name,
            signals: $signalsMeta,
            breakdown: $breakdown,
        );
    }
}
