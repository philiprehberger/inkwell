<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $cursor = $request->query('cursor');
        $subjectType = $request->query('subject_type');

        $query = $workspace->auditEvents()->orderByDesc('id');
        if ($cursor) {
            $query->where('id', '<', $cursor);
        }
        if ($subjectType) {
            $query->where('subject_type', $subjectType);
        }

        $events = $query->limit($limit + 1)->get();
        $hasMore = $events->count() > $limit;
        $page = $events->take($limit);

        return response()->json([
            'data' => $page->map(fn ($e) => [
                'id' => $e->id,
                'actor_type' => $e->actor_type,
                'actor_id' => $e->actor_id,
                'actor_label' => $e->actor_label,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'action' => $e->action,
                'diff' => $e->diff,
                'reason' => $e->reason,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $hasMore ? $page->last()->id : null,
        ]);
    }

    private function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }
}
