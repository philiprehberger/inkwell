<?php

namespace App\Services\Spam;

use App\Models\Form;

/**
 * What a SpamSignal sees about an in-flight submission.
 *
 * Immutable bag — signals don't mutate context, they only inspect it and
 * return a SignalResult.
 */
final readonly class SubmissionContext
{
    public function __construct(
        public Form $form,
        /** @var array<string, mixed> */
        public array $payload,
        /** @var array<string, mixed> */
        public array $raw,
        public ?string $clientIp,
        public ?string $userAgent,
        public ?string $referer,
        public ?int $renderedAtTimestamp,
        public ?string $captchaToken,
    ) {}
}
