<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Boot the WorkspaceScope global scope + expose the `workspace()` relation.
 */
trait BelongsToWorkspace
{
    protected static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);
        static::creating(function ($model) {
            if (! $model->workspace_id) {
                $id = WorkspaceScope::currentWorkspaceId();
                if ($id !== null) {
                    $model->workspace_id = $id;
                }
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
