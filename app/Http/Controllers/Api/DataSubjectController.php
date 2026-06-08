<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeDataSubjectJob;
use App\Models\DataSubjectRequest;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DataSubjectController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ])->validate();

        $workspace = $this->workspace($request);
        $needle = hash('sha256', strtolower(trim($data['email'])));

        // Scan — acceptable at portfolio scale. Production would index an
        // email_hash column on submissions for O(log n) lookup.
        $matches = [];
        Submission::withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->id)
            ->whereNull('pii_purged_at')
            ->cursor()
            ->each(function (Submission $s) use ($needle, &$matches) {
                $payload = $s->payload ?: [];
                foreach (['email', 'email_address'] as $k) {
                    if (isset($payload[$k]) && is_string($payload[$k])) {
                        if (hash('sha256', strtolower(trim($payload[$k]))) === $needle) {
                            $matches[] = $s;
                            return;
                        }
                    }
                }
            });

        $payload = [
            'email' => $data['email'],
            'submissions' => array_map(static fn (Submission $s) => [
                'submission_id' => $s->id,
                'form_id' => $s->form_id,
                'form_name' => $s->form?->name ?? 'unknown',
                'created_at' => $s->created_at?->toIso8601String(),
                'state' => $s->state,
            ], $matches),
            'count' => count($matches),
        ];

        return response()->json($payload);
    }

    public function delete(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'reason' => ['required', 'string', 'min:1'],
        ])->validate();

        $workspace = $this->workspace($request);
        $emailHash = hash('sha256', strtolower(trim($data['email'])));

        $request = DataSubjectRequest::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'email_hash' => $emailHash,
            'reason' => $data['reason'],
            'state' => DataSubjectRequest::STATE_QUEUED,
        ]);

        AuditLogger::record($workspace, 'data_subject_request', $request->id, 'queued', [
            'email_hash' => $emailHash,
            'reason' => $data['reason'],
        ], request: app(Request::class));

        PurgeDataSubjectJob::dispatch($request->id);

        return response()->json($this->serialize($request), 202);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $req = DataSubjectRequest::findOrFail($id);
        return response()->json($this->serialize($req));
    }

    private function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }

    private function serialize(DataSubjectRequest $r): array
    {
        return [
            'id' => $r->id,
            'email_hash' => $r->email_hash,
            'reason' => $r->reason,
            'state' => $r->state,
            'submissions_purged' => $r->submissions_purged,
            'created_at' => $r->created_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
        ];
    }
}
