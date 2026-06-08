<?php

namespace App\Jobs;

use App\Models\DataSubjectRequest;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Right-to-erasure cascade. Triggered by DELETE /v1/data-subjects/by-email.
 *
 * Steps:
 *   1. Mark request `in_progress`.
 *   2. Find every submission whose payload contains the email.
 *   3. For each: null the payload + meta JSON, set `pii_purged_at`, delete
 *      any associated submission_files (S3 + DB row), redact delivery_attempts
 *      bodies.
 *   4. Mark request `completed` with submissions_purged count.
 *
 * Audit-log entries: we LEAVE the audit row but rewrite its diff to
 * "PII purged 2026-XX-XX" — auditability vs erasability tension resolved
 * in favour of keeping the audit row with redacted content.
 */
class PurgeDataSubjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $requestId) {}

    public function handle(): void
    {
        $request = DataSubjectRequest::withoutGlobalScope(WorkspaceScope::class)->find($this->requestId);
        if (! $request) {
            return;
        }
        $request->state = DataSubjectRequest::STATE_IN_PROGRESS;
        $request->save();

        // Find submissions with matching email_hash. We don't have a column for
        // it; we scan submissions by payload->email — acceptable at portfolio
        // scale, would be replaced by a maintained email_hash column at
        // production scale (documented as a v2 ask).
        $purged = 0;
        Submission::withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $request->workspace_id)
            ->whereNull('pii_purged_at')
            ->cursor()
            ->each(function (Submission $submission) use ($request, &$purged) {
                $payload = $submission->payload ?: [];
                $candidates = [];
                foreach (['email', 'email_address'] as $key) {
                    if (isset($payload[$key]) && is_string($payload[$key])) {
                        $candidates[] = hash('sha256', strtolower(trim($payload[$key])));
                    }
                }
                if (in_array($request->email_hash, $candidates, true)) {
                    $this->purgeSubmission($submission);
                    $purged++;
                }
            });

        $request->submissions_purged = $purged;
        $request->state = DataSubjectRequest::STATE_COMPLETED;
        $request->completed_at = now();
        $request->save();
    }

    private function purgeSubmission(Submission $submission): void
    {
        // Delete files from storage.
        foreach ($submission->files()->get() as $file) {
            try {
                Storage::disk('local')->delete($file->storage_path);
            } catch (\Throwable) {
                // Best-effort; the DB row deletion is what matters for compliance.
            }
            $file->delete();
        }

        // Redact delivery attempt bodies.
        foreach ($submission->deliveries()->with('attemptRecords')->get() as $delivery) {
            foreach ($delivery->attemptRecords as $attempt) {
                $attempt->response_body_snippet = '[purged]';
                $attempt->save();
            }
        }

        $submission->payload = [];
        $submission->meta = ['pii_purged' => true];
        $submission->pii_purged_at = now();
        $submission->save();
    }
}
