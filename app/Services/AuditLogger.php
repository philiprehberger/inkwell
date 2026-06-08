<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Append-only audit logger. Uses WorkspaceScope::withoutGlobalScope so the
 * caller can record events without depending on request context.
 *
 * Production hardening: the app DB user has INSERT-only grant on
 * `audit_events`; revoking UPDATE / DELETE is enforced at the MySQL level.
 * Documented in infra/db/grants.sql (Phase 8).
 */
final class AuditLogger
{
    public static function record(
        Workspace $workspace,
        string $subjectType,
        string $subjectId,
        string $action,
        array $diff = [],
        ?string $reason = null,
        ?Request $request = null,
    ): AuditEvent {
        $actorType = 'system';
        $actorId = null;
        $actorLabel = 'system';

        if ($request !== null) {
            $key = $request->attributes->get('api_key');
            if ($key instanceof ApiKey) {
                $actorType = 'api_key';
                $actorId = $key->id;
                $actorLabel = $key->name ?: $key->prefix.'…'.$key->last_four;
            } elseif (auth()->check()) {
                $user = auth()->user();
                $actorType = 'user';
                $actorId = (string) $user->getKey();
                $actorLabel = (string) ($user->email ?? $user->name ?? 'user');
            }
        }

        return AuditEvent::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'diff' => $diff,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
