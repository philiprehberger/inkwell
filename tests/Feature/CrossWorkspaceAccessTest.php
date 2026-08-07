<?php

namespace Tests\Feature;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every workspace-owned resource must 404 for a foreign ID.
 *
 * WorkspaceScopeTest already covers forms, but forms are protected by explicit
 * relationship scoping (`$workspace->forms()`), so it passes whether or not the
 * global scope functions. These cases cover the resources that had no such
 * protection: a submission reached through `promote` or `replay-deliveries`
 * returns its full payload, and a data-subject request returns the erasure
 * record — both by bare `findOrFail`, which is only safe if WorkspaceScope is
 * actually applying a predicate.
 *
 * @see WorkspaceScopeResolutionTest for the unit-level cause.
 */
class CrossWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function submissionFor(Workspace $workspace, string $state): Submission
    {
        $form = $this->makeForm($workspace);

        return Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['email' => 'victim@example.com', 'message' => 'confidential'],
            'meta' => ['client_ip' => '203.0.113.7'],
            'spam_score' => 0,
            'spam_signals' => [],
            'state' => $state,
            'payload_hash' => hash('sha256', uniqid()),
        ]);
    }

    public function test_promote_404s_on_another_workspaces_submission(): void
    {
        [, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $victim = $this->submissionFor($wB, Submission::STATE_SPAM);

        $this->postJson("/v1/submissions/{$victim->id}/promote", [], $this->authed($keyA))
            ->assertStatus(404);
    }

    public function test_replay_404s_on_another_workspaces_submission(): void
    {
        [, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $victim = $this->submissionFor($wB, Submission::STATE_CLEAN);

        $this->postJson("/v1/submissions/{$victim->id}/replay-deliveries", [], $this->authed($keyA))
            ->assertStatus(404);
    }

    public function test_promote_does_not_leak_the_payload_of_a_foreign_submission(): void
    {
        [, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $victim = $this->submissionFor($wB, Submission::STATE_SPAM);

        $response = $this->postJson("/v1/submissions/{$victim->id}/promote", [], $this->authed($keyA));

        $response->assertDontSee('victim@example.com');
        $response->assertDontSee('confidential');
        $response->assertDontSee('203.0.113.7');
    }

    public function test_show_404s_on_another_workspaces_submission(): void
    {
        [, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $victim = $this->submissionFor($wB, Submission::STATE_CLEAN);

        $this->getJson("/v1/submissions/{$victim->id}", $this->authed($keyA))
            ->assertStatus(404);
    }

    public function test_destination_endpoints_404_on_another_workspaces_form(): void
    {
        [, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $form = $this->makeForm($wB);

        $this->getJson("/v1/forms/{$form->id}/destinations", $this->authed($keyA))
            ->assertStatus(404);
    }

    public function test_data_subject_status_404s_on_another_workspaces_request(): void
    {
        [, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $req = \App\Models\DataSubjectRequest::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $wB->id,
            'email_hash' => hash('sha256', 'victim@example.com'),
            'reason' => 'gdpr-erasure',
            'state' => 'pending',
        ]);

        $this->getJson("/v1/data-subjects/requests/{$req->id}", $this->authed($keyA))
            ->assertStatus(404);
    }
}
