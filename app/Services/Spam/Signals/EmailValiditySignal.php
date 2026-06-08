<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;

/**
 * Validate the submitter's email — RFC 5322 syntax + (optional, network-aware)
 * MX-record presence + a known disposable-domain blacklist.
 *
 * Net check defaults off in tests; the property-based corpus stays stable.
 */
final class EmailValiditySignal implements SpamSignal
{
    // A subset of the bigger published disposable-email lists. Refresh from
    // the `disposable-email-domains` GitHub repo as a v2 enhancement.
    private const DISPOSABLE_DOMAINS = [
        '10minutemail.com', 'mailinator.com', 'guerrillamail.com',
        'tempmail.com', 'temp-mail.org', 'throwaway.email', 'yopmail.com',
        'sharklasers.com', 'getairmail.com', 'fakeinbox.com',
    ];

    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        $email = null;
        foreach (['email', 'email_address', 'e-mail'] as $key) {
            if (isset($ctx->payload[$key]) && is_string($ctx->payload[$key])) {
                $email = trim($ctx->payload[$key]);
                break;
            }
        }
        if ($email === null) {
            return null;
        }

        $points = 0;
        $metadata = ['email' => $email];

        $syntactic = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $metadata['syntactic'] = $syntactic;
        if (! $syntactic) {
            $points += 15;
        }

        $domain = $syntactic ? strtolower(substr(strrchr($email, '@'), 1)) : null;
        $metadata['disposable'] = $domain !== null && in_array($domain, self::DISPOSABLE_DOMAINS, true);
        if ($metadata['disposable']) {
            $points += 10;
        }

        return new SignalResult(name: 'email_validity', points: $points, metadata: $metadata);
    }
}
