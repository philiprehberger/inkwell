<?php

namespace App\Services\Spam;

/**
 * Decision states emitted by SpamScorer. These mirror the seven Submission
 * states; only the four that can be set at ingest time are listed here
 * (`pending` is a transient ingest pre-state; `promoted` and `archived` are
 * set later by buyer action and the archive cron).
 */
final class SubmissionState
{
    public const CLEAN = 'clean';
    public const SPAM = 'spam';
    public const QUARANTINED = 'quarantined';
    public const REJECTED = 'rejected';
}
