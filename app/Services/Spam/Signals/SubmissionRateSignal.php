<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;
use Illuminate\Support\Facades\Cache;

/**
 * More than 10 submissions from this IP to this form in the last 60 seconds =
 * suspicious. Doesn't hard-block (rate-limit middleware already handles flood);
 * adds friction.
 */
final class SubmissionRateSignal implements SpamSignal
{
    private const WINDOW = 60;
    private const THRESHOLD = 10;
    private const SUSPICIOUS_POINTS = 25;

    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        $ip = $ctx->clientIp;
        if (! is_string($ip) || $ip === '') {
            return null;
        }
        $key = "spam:rate:{$ctx->form->id}:{$ip}";
        $count = (int) Cache::get($key, 0);
        Cache::put($key, $count + 1, self::WINDOW);

        $exceeded = $count >= self::THRESHOLD;
        return new SignalResult(
            name: 'submission_rate',
            points: $exceeded ? self::SUSPICIOUS_POINTS : 0,
            metadata: ['count_in_window' => $count + 1, 'window_seconds' => self::WINDOW, 'threshold' => self::THRESHOLD],
        );
    }
}
