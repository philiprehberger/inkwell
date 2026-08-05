<?php

namespace App\Services\Destinations;

use App\Models\Submission;

/**
 * Plan 5.5 — fixed envelope shapes instead of tenant-authored templates.
 *
 * A template engine over tenant input is a code-execution surface, and
 * "restricted, non-eval expression syntax" is an intention rather than a
 * guarantee. These three shapes cover the actual requirement — letting Inkwell
 * post directly into Webhook Relay — with no injection surface at all.
 *
 * A sandboxed engine is deferred to the roadmap with named guarantees.
 */
enum EnvelopeShape: string
{
    /** Today's envelope. Default, and byte-for-byte unchanged. */
    case InkwellNative = 'inkwell-native';

    /** What Webhook Relay's POST /v1/events requires. */
    case WebhookRelay = 'webhook-relay';

    /** The submission payload alone, no wrapper. */
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::InkwellNative => 'Inkwell native (default)',
            self::WebhookRelay => 'Webhook Relay envelope',
            self::Flat => 'Flat payload only',
        };
    }

    public function build(Submission $submission, ?string $eventType = null): array
    {
        $native = [
            'id' => $submission->id,
            'form_id' => $submission->form_id,
            'state' => $submission->state,
            'spam_score' => $submission->spam_score,
            'payload' => $submission->payload,
            'meta' => $submission->meta,
            'created_at' => $submission->created_at?->toIso8601String(),
        ];

        return match ($this) {
            self::InkwellNative => $native,
            self::WebhookRelay => [
                'type' => $eventType ?: 'form.submission.created',
                'payload' => $native,
            ],
            self::Flat => $submission->payload ?? [],
        };
    }
}
