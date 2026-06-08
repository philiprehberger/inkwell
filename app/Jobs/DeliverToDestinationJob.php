<?php

namespace App\Jobs;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\FormDestination;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Services\Destinations\DestinationRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Per-(submission, destination) delivery job. Always carries the
 * (submission_id, destination_id, replay_sequence) idempotency key — Horizon
 * job redrives are safe.
 *
 * Queue: inkwell-fast for HTTP-only destinations (email-via-provider, webhook,
 * Slack, Discord), inkwell-slow for OAuth connectors (added in Phase 5).
 */
class DeliverToDestinationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [30, 120, 600];

    public function __construct(
        public string $deliveryId,
    ) {}

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = Delivery::find($this->deliveryId);
        if ($delivery === null || $delivery->state === Delivery::STATE_SENT) {
            return;
        }
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->find($delivery->submission_id);
        $destination = FormDestination::find($delivery->destination_id);
        if (! $submission || ! $destination || ! $destination->enabled) {
            $delivery->state = Delivery::STATE_FAILED;
            $delivery->save();
            return;
        }

        $impl = DestinationRegistry::fromConfig()->get($destination->kind);
        if ($impl === null) {
            $delivery->state = Delivery::STATE_FAILED;
            $delivery->save();
            return;
        }

        $delivery->attempts = ($delivery->attempts ?? 0) + 1;
        $result = $impl->deliver($submission, $destination);

        DeliveryAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => $delivery->attempts,
            'request_summary' => $result->requestSummary,
            'response_status' => $result->responseStatus,
            'response_body_snippet' => $result->responseBodySnippet,
            'latency_ms' => $result->latencyMs,
            'error_code' => $result->errorCode,
        ]);

        $delivery->final_status_code = $result->responseStatus;
        $delivery->last_attempted_at = now();

        if ($result->success) {
            $delivery->state = Delivery::STATE_SENT;
            $delivery->save();
            $destination->forceFill(['last_attempted_at' => now(), 'health' => 'healthy'])->save();
            return;
        }

        if ($delivery->attempts >= $this->tries) {
            $delivery->state = Delivery::STATE_DEAD;
            $delivery->save();
            $destination->forceFill(['last_attempted_at' => now(), 'health' => 'degraded'])->save();
            return;
        }

        $delivery->state = Delivery::STATE_FAILED;
        $delivery->save();
        // Re-throw so Horizon honours $backoff for the retry.
        throw new \RuntimeException("destination {$destination->id} failed; will retry");
    }
}
