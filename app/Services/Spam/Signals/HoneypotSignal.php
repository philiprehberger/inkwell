<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;

/**
 * Hidden field that real visitors leave empty. If it has any value, the
 * submitter is a script. Hard-block (points = 100).
 */
final class HoneypotSignal implements SpamSignal
{
    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        $field = $ctx->form->honeypot_field ?: '_subject_honeypot';
        $value = $ctx->raw[$field] ?? null;
        $filled = is_string($value) && trim($value) !== '';
        return new SignalResult(
            name: 'honeypot',
            points: $filled ? 100 : 0,
            metadata: ['field' => $field, 'filled' => $filled],
        );
    }
}
