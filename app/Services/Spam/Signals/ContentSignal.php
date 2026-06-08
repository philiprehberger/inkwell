<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;

/**
 * Heuristic content scoring on the longest free-text field in the payload.
 *
 * Signals (cumulative within this signal, capped at 25 total points):
 *   - ≥ 3 URLs in the body → +10
 *   - All-caps ratio > 0.5 → +5
 *   - Phone-number count ≥ 3 → +5
 *   - Body shorter than 6 chars (likely a script test) → +5
 *
 * Not perfect — false positives possible. The buyer's visible breakdown +
 * threshold tuning + the per-workspace classifier (v2) are the corrective
 * forces.
 */
final class ContentSignal implements SpamSignal
{
    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        $longest = '';
        foreach ($ctx->payload as $value) {
            if (is_string($value) && strlen($value) > strlen($longest)) {
                $longest = $value;
            }
        }
        if ($longest === '') {
            return null;
        }

        $points = 0;
        $metadata = [];

        $urlCount = preg_match_all('/(https?:\/\/|www\.)\S+/i', $longest);
        $metadata['url_count'] = $urlCount;
        if ($urlCount >= 3) {
            $points += 10;
        }

        $alpha = preg_replace('/[^a-zA-Z]/', '', $longest);
        if (strlen($alpha) >= 12) {
            $upperCount = strlen(preg_replace('/[^A-Z]/', '', $alpha));
            $ratio = $upperCount / max(1, strlen($alpha));
            $metadata['all_caps_ratio'] = round($ratio, 3);
            if ($ratio > 0.5) {
                $points += 5;
            }
        }

        $phoneCount = preg_match_all('/(?:\+?\d[\d\s().-]{8,}\d)/', $longest);
        $metadata['phone_count'] = $phoneCount;
        if ($phoneCount >= 3) {
            $points += 5;
        }

        $bodyLength = strlen(trim($longest));
        $metadata['longest_field_length'] = $bodyLength;
        if ($bodyLength > 0 && $bodyLength < 6) {
            $points += 5;
        }

        $points = min(25, $points);
        return new SignalResult(name: 'content', points: $points, metadata: $metadata);
    }
}
