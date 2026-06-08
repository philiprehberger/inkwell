<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope: every workspace-owned model is auto-filtered to the current
 * request's workspace.
 *
 * Resolution order:
 *   1. Request attribute `workspace` (set by ApiKeyAuth middleware) — for API requests.
 *   2. `auth()->user()->current_workspace_id` — for Filament admin sessions.
 *   3. No filter — for CLI, queue workers, tests not specifying a workspace.
 *
 * Models opt in by importing `BelongsToWorkspace` trait (which boots this scope).
 * `withoutGlobalScope(WorkspaceScope::class)` is the documented escape hatch for
 * cross-workspace operator actions and the PII-purge job.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = self::currentWorkspaceId();
        if ($workspaceId === null) {
            return;
        }
        $builder->where($model->getTable().'.workspace_id', $workspaceId);
    }

    public static function currentWorkspaceId(): ?string
    {
        // Prefer the request-attached workspace; fall back to the user's current.
        if (function_exists('request') && request() !== null) {
            $w = request()->attributes->get('workspace');
            if ($w !== null && is_object($w) && property_exists($w, 'id')) {
                return $w->id;
            }
            if (is_string($w)) {
                return $w;
            }
        }
        if (function_exists('auth') && auth()->check()) {
            return auth()->user()->current_workspace_id ?? null;
        }
        return null;
    }
}
