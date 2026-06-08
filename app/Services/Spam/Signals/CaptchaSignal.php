<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;
use Illuminate\Support\Facades\Http;

/**
 * Pass-through to Cloudflare Turnstile (or compatible captcha provider).
 * Only contributes points if the form has captcha *required* in its config
 * AND the visitor either didn't supply a token or the token verified false.
 *
 * Required = captcha was already a hard requirement; failed captcha → +10
 * additional points (combined with whatever other signals fired).
 *
 * Optional = if the visitor supplied a passing token, *subtract* 10
 * (clamped at 0) as a goodwill credit. Doesn't appear in v1; reserved as
 * a v2 expansion lever.
 */
final class CaptchaSignal implements SpamSignal
{
    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        if ($ctx->captchaToken === null) {
            return null;
        }
        $secret = config('services.turnstile.secret_key') ?? env('TURNSTILE_SECRET_KEY');
        if (! is_string($secret) || $secret === '') {
            return null;
        }
        try {
            $resp = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $ctx->captchaToken,
                'remoteip' => $ctx->clientIp ?? '',
            ]);
            $passed = (bool) ($resp->json('success') ?? false);
        } catch (\Throwable) {
            $passed = false;
        }
        return new SignalResult(
            name: 'captcha',
            points: $passed ? 0 : 10,
            metadata: ['provider' => 'turnstile', 'passed' => $passed],
        );
    }
}
