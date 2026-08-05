<?php

namespace App\Conformance;

use App\Jobs\DeliverToDestinationJob;
use App\Models\FormDestination;
use App\Models\Submission;
use App\Services\Destinations\WebhookDestination;
use Illuminate\Support\Facades\Http;
use PhilipRehberger\Interchange\Conformance\ConformanceHarness;
use PhilipRehberger\Interchange\Signing\StandardWebhooksScheme;

/**
 * Plan 5.14 / D-4 — Inkwell's conformance adapter.
 *
 * Declares how to drive the three paths the contract constrains. The
 * assertions live in the conformance package; this only knows Inkwell's
 * internals, which is the division of labour the interface exists for.
 */
class InkwellConformanceHarness implements ConformanceHarness
{
    public const TEST_SECRET = 'conformance-secret-not-a-real-one';

    public function serviceSlug(): string
    {
        return 'inkwell';
    }

    public function inboundTracePath(): string
    {
        return '/v1/healthz';
    }

    public function supportedSchemes(): array
    {
        return ['inkwell-v0', 'standard-webhooks'];
    }

    public function triggerSignedDelivery(string $scheme): array
    {
        $secret = $scheme === 'standard-webhooks'
            ? StandardWebhooksScheme::generateSecret()
            : self::TEST_SECRET;

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response(['ok' => true], 200);
        });

        $destination = new FormDestination;
        $destination->id = '01JCONFORMANCE0DESTINATION';
        $destination->form_id = '01JCONFORMANCE0FORM0000001';
        $destination->kind = FormDestination::KIND_WEBHOOK;
        $destination->config = ['url' => 'https://example.com/conformance', 'secret' => $secret];
        $destination->signature_scheme = $scheme;

        (new WebhookDestination)->deliver($this->submission(), $destination);

        return [
            // Lowercased: HTTP header names are case-insensitive, and a
            // conformance assertion must not depend on how a sender happened
            // to capitalise them.
            'headers' => $captured === null ? [] : array_change_key_case(array_map(
                fn (array $values) => $values[0] ?? '',
                $captured->headers(),
            )),
            'body' => $captured?->body() ?? '',
            'secret' => $secret,
        ];
    }

    public function dispatchTracedJob(): void
    {
        // Must genuinely enqueue: running inline would prove nothing about the
        // queue boundary, which is the whole point of `trace.queue`.
        DeliverToDestinationJob::dispatch('01JCONFORMANCE0DELIVERY000');
    }

    private function submission(): Submission
    {
        $submission = new Submission;
        $submission->id = '01JCONFORMANCE0SUBMISSION';
        $submission->form_id = '01JCONFORMANCE0FORM0000001';
        $submission->state = 'clean';
        $submission->spam_score = 0.0;
        $submission->payload = ['email' => 'conformance@example.test'];
        $submission->meta = [];
        $submission->created_at = now();

        return $submission;
    }
}
