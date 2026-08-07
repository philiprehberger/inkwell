<?php

namespace Tests\Unit;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The specific thing that broke.
 *
 * `ApiKeyAuth` attaches a Workspace **model** to the request. The resolver used
 * to gate on `property_exists($w, 'id')`, which is false for Eloquent — the key
 * lives in the internal `$attributes` array, not a declared property. So the
 * resolver returned null on every API request and the global scope applied no
 * predicate at all.
 *
 * The feature tests catch the consequence; these catch the cause, which is the
 * only level at which the failure is legible.
 */
class WorkspaceScopeResolutionTest extends TestCase
{
    private function requestWithWorkspaceAttribute(mixed $value): void
    {
        $request = Request::create('/v1/submissions/x/promote', 'POST');
        $request->attributes->set('workspace', $value);
        $this->app->instance('request', $request);
    }

    public function test_it_resolves_the_id_from_an_eloquent_workspace_model(): void
    {
        $workspace = new Workspace(['name' => 'Alpha']);
        $workspace->id = 'ws_01alpha';

        $this->requestWithWorkspaceAttribute($workspace);

        $this->assertSame(
            'ws_01alpha',
            WorkspaceScope::currentWorkspaceId(),
            'A Workspace model must resolve. property_exists() is false for Eloquent attributes — '
            .'use `instanceof Model` + getKey().'
        );
    }

    public function test_it_still_resolves_a_plain_string_workspace_id(): void
    {
        $this->requestWithWorkspaceAttribute('ws_01string');

        $this->assertSame('ws_01string', WorkspaceScope::currentWorkspaceId());
    }

    public function test_the_generated_query_carries_a_workspace_predicate(): void
    {
        $workspace = new Workspace(['name' => 'Alpha']);
        $workspace->id = 'ws_01alpha';

        $this->requestWithWorkspaceAttribute($workspace);

        $this->assertStringContainsString(
            'workspace_id',
            Submission::whereKey('01TEST')->toSql(),
            'The global scope must add a workspace_id predicate; without it every '
            .'bare findOrFail() in a controller is a cross-tenant read.'
        );
    }

    public function test_it_returns_null_when_no_workspace_is_attached(): void
    {
        $this->requestWithWorkspaceAttribute(null);

        $this->assertNull(WorkspaceScope::currentWorkspaceId());
    }
}
