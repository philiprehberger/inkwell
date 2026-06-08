<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\SchemaValidator;
use App\Services\SubmissionDedupCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Submission management (auth required) lives here. The public ingest endpoint
 * lives in IngestController.
 */
class SubmissionsController extends Controller
{
    public function index(Request $request, string $formId): JsonResponse
    {
        $form = $this->workspace($request)->forms()->findOrFail($formId);
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $cursor = $request->query('cursor');
        $state = $request->query('state');

        $query = $form->submissions()->orderByDesc('id');
        if ($cursor) {
            $query->where('id', '<', $cursor);
        }
        if ($state !== null && in_array($state, [
            Submission::STATE_PENDING,
            Submission::STATE_CLEAN,
            Submission::STATE_SPAM,
            Submission::STATE_QUARANTINED,
            Submission::STATE_PROMOTED,
            Submission::STATE_REJECTED,
            Submission::STATE_ARCHIVED,
        ], true)) {
            $query->where('state', $state);
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json([
            'data' => $page->map(fn ($s) => $this->serializeSummary($s))->all(),
            'next_cursor' => $hasMore ? $page->last()->id : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $submission = $this->workspace($request)
            ->forms()
            ->getQuery()
            ->newQuery()
            ->from('submissions')
            ->where('id', $id)
            ->where('workspace_id', $this->workspace($request)->id)
            ->first();
        if (! $submission) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Not found',
                'status' => 404,
            ], 404, ['Content-Type' => 'application/problem+json']);
        }
        $model = Submission::with(['deliveries.destination', 'files'])->findOrFail($id);
        return response()->json($this->serializeDetail($model));
    }

    public function promote(Request $request, string $id): JsonResponse
    {
        $submission = Submission::with('deliveries')->findOrFail($id);
        if (! in_array($submission->state, [Submission::STATE_SPAM, Submission::STATE_QUARANTINED], true)) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Invalid state',
                'status' => 400,
                'detail' => "Only spam or quarantined submissions can be promoted. Current state: {$submission->state}.",
            ], 400, ['Content-Type' => 'application/problem+json']);
        }

        $previous = $submission->state;
        $submission->state = Submission::STATE_PROMOTED;
        $submission->save();

        AuditLogger::record($this->workspace($request), 'submission', $submission->id, 'promoted', [
            'previous_state' => $previous,
        ], request: $request);

        \App\Jobs\DispatchDestinationsJob::dispatch($submission->id);

        return response()->json($this->serializeDetail($submission->fresh(['deliveries.destination', 'files'])));
    }

    public function replay(Request $request, string $id): JsonResponse
    {
        $submission = Submission::with('deliveries')->findOrFail($id);
        $rateLimitKey = 'replay:'.$this->workspace($request)->id;
        $limit = 10;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateLimitKey, $limit)) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Too many requests',
                'status' => 429,
                'detail' => "Replay endpoint is limited to {$limit} requests per minute per workspace.",
            ], 429, ['Content-Type' => 'application/problem+json']);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($rateLimitKey, 60);

        // Bump replay_sequence on failed deliveries so idempotency keys differ
        // from the original attempt.
        $submission->deliveries()->where('state', '!=', \App\Models\Delivery::STATE_SENT)
            ->update(['replay_sequence' => \Illuminate\Support\Facades\DB::raw('replay_sequence + 1'), 'state' => \App\Models\Delivery::STATE_PENDING]);

        AuditLogger::record($this->workspace($request), 'submission', $submission->id, 'replay_queued', [], request: $request);

        return response()->json($this->serializeDetail($submission->fresh(['deliveries.destination', 'files'])));
    }

    private function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }

    private function serializeSummary(Submission $s): array
    {
        return [
            'id' => $s->id,
            'form_id' => $s->form_id,
            'state' => $s->state,
            'spam_score' => $s->spam_score,
            'created_at' => $s->created_at?->toIso8601String(),
            'pii_purged_at' => $s->pii_purged_at?->toIso8601String(),
        ];
    }

    private function serializeDetail(Submission $s): array
    {
        return array_merge($this->serializeSummary($s), [
            'payload' => $s->isPiiPurged() ? null : $s->payload,
            'meta' => $s->meta,
            'spam_signals' => $s->spam_signals,
            'deliveries' => $s->deliveries->map(fn ($d) => [
                'id' => $d->id,
                'destination_id' => $d->destination_id,
                'destination_kind' => $d->destination?->kind,
                'state' => $d->state,
                'attempts' => $d->attempts,
                'final_status_code' => $d->final_status_code,
                'last_attempted_at' => $d->last_attempted_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
