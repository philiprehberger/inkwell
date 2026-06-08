<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;

/**
 * Real visitors take > 2 seconds to fill a form. Bots submit instantly.
 * Requires the optional 3 KB widget (Phase 7) which writes the page-render
 * timestamp into the hidden `_inkwell_ts` field. Without the widget the
 * signal returns null (don't penalise no-JS visitors).
 */
final class TimingSignal implements SpamSignal
{
    private const MIN_SECONDS = 2;
    private const SUSPICIOUS_POINTS = 25;

    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        $rendered = $ctx->raw['_inkwell_ts'] ?? null;
        if (! is_string($rendered) && ! is_int($rendered)) {
            return null;
        }
        $renderedTs = (int) $rendered;
        if ($renderedTs <= 0) {
            return null;
        }
        $elapsed = time() - $renderedTs;
        $tooFast = $elapsed < self::MIN_SECONDS;
        return new SignalResult(
            name: 'timing',
            points: $tooFast ? self::SUSPICIOUS_POINTS : 0,
            metadata: ['elapsed_seconds' => $elapsed, 'min_seconds' => self::MIN_SECONDS],
        );
    }
}
