<?php

namespace App\Jobs;

use App\Models\Delivery;
use App\Models\FormDestination;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Fan-out parent job: looks up the submission's form's destinations, creates
 * a Delivery row per destination, and dispatches one DeliverToDestinationJob
 * for each. Per-destination idempotency makes worker-crash retries safe.
 *
 * Queue: inkwell-fast (dispatcher is cheap).
 */
class DispatchDestinationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $submissionId,
    ) {
        $this->onQueue('inkwell-fast');
    }

    public function handle(): void
    {
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->find($this->submissionId);
        if (! $submission) {
            return;
        }
        if (! in_array($submission->state, [Submission::STATE_CLEAN, Submission::STATE_PROMOTED], true)) {
            return;
        }

        $destinations = FormDestination::where('form_id', $submission->form_id)
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();

        foreach ($destinations as $destination) {
            $delivery = Delivery::firstOrCreate(
                [
                    'submission_id' => $submission->id,
                    'destination_id' => $destination->id,
                ],
                [
                    'state' => Delivery::STATE_PENDING,
                    'attempts' => 0,
                    'replay_sequence' => 0,
                ],
            );

            DeliverToDestinationJob::dispatch($delivery->id)
                ->onQueue($destination->isFastQueue() ? 'inkwell-fast' : 'inkwell-slow');
        }
    }
}
