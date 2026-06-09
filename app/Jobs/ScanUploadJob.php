<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Services\Files\ClamScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Out-of-band malware scan for uploaded files. The submission is already
 * acknowledged to the visitor; if a file turns out to be infected:
 *   - SubmissionFile.scan_state = 'infected'
 *   - File moved into the quarantine bucket prefix (segregated from
 *     buyer-accessible storage).
 *   - Containing submission's state → 'quarantined' (visible to buyer in
 *     the admin Quarantined tab; destinations don't fan out).
 *
 * Queue: inkwell-scan (separate supervisord process so a slow scan doesn't
 * starve the fan-out queues).
 */
class ScanUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public string $submissionFileId)
    {
        $this->onQueue('inkwell-scan');
    }

    public function handle(ClamScanner $scanner): void
    {
        $file = SubmissionFile::find($this->submissionFileId);
        if (! $file || $file->scan_state !== SubmissionFile::SCAN_PENDING) {
            return;
        }

        $bytes = Storage::get($file->storage_path);
        if ($bytes === null) {
            $file->scan_state = SubmissionFile::SCAN_ERROR;
            $file->scanned_at = now();
            $file->save();
            return;
        }

        [$result, $reason] = $scanner->scan($bytes);
        $file->scanned_at = now();

        if ($result === ClamScanner::RESULT_INFECTED) {
            // Move into the quarantine prefix; never serve from buyer paths.
            $quarantinePath = 'quarantine/'.basename($file->storage_path);
            Storage::move($file->storage_path, $quarantinePath);
            $file->scan_state = SubmissionFile::SCAN_INFECTED;
            $file->storage_path = $quarantinePath;
            $file->save();

            $submission = Submission::find($file->submission_id);
            if ($submission && $submission->state === Submission::STATE_CLEAN) {
                $submission->state = Submission::STATE_QUARANTINED;
                $submission->save();
            }
            return;
        }

        if ($result === ClamScanner::RESULT_ERROR) {
            $file->scan_state = SubmissionFile::SCAN_ERROR;
        } else {
            $file->scan_state = SubmissionFile::SCAN_CLEAN;
        }
        $file->save();
    }
}
