<?php

namespace Tests\Feature;

use App\Jobs\PurgeDataSubjectJob;
use App\Models\DataSubjectRequest;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_subject_lookup_finds_matching_submissions(): void
    {
        [$workspace, $key] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['name' => 'Alice', 'email' => 'alice@example.com', 'message' => 'Hello'],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'h1',
        ]);
        Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['name' => 'Bob', 'email' => 'bob@example.com', 'message' => 'Hi'],
            'meta' => [],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'h2',
        ]);

        $resp = $this->postJson('/v1/data-subjects/lookup', [
            'email' => 'alice@example.com',
        ], $this->authed($key));

        $resp->assertOk();
        $resp->assertJsonPath('count', 1);
        $resp->assertJsonPath('submissions.0.form_name', $form->name);
    }

    public function test_data_subject_delete_queues_purge_job(): void
    {
        Queue::fake();
        [, $key] = $this->freshWorkspace();

        $resp = $this->deleteJson('/v1/data-subjects/by-email', [
            'email' => 'alice@example.com',
            'reason' => 'GDPR Article 17 erasure request',
        ], $this->authed($key));

        $resp->assertStatus(202);
        $resp->assertJsonPath('state', 'queued');
        Queue::assertPushed(PurgeDataSubjectJob::class);
    }

    public function test_purge_job_redacts_payload_and_marks_purged(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'payload' => ['name' => 'Carol', 'email' => 'carol@example.com', 'message' => 'Secret PII'],
            'meta' => ['client_ip' => '203.0.113.42'],
            'state' => Submission::STATE_CLEAN,
            'payload_hash' => 'h3',
        ]);

        $request = DataSubjectRequest::create([
            'workspace_id' => $workspace->id,
            'email_hash' => hash('sha256', 'carol@example.com'),
            'reason' => 'Request from buyer',
            'state' => DataSubjectRequest::STATE_QUEUED,
        ]);

        (new PurgeDataSubjectJob($request->id))->handle();

        $submission->refresh();
        $this->assertSame([], $submission->payload);
        $this->assertNotNull($submission->pii_purged_at);
        $this->assertTrue($submission->meta['pii_purged']);

        $request->refresh();
        $this->assertSame(DataSubjectRequest::STATE_COMPLETED, $request->state);
        $this->assertSame(1, $request->submissions_purged);
    }

    public function test_non_admin_key_cannot_call_compliance_endpoints(): void
    {
        [$workspace] = $this->freshWorkspace();
        [, $limited] = \App\Models\ApiKey::mint($workspace, ['forms.read'], 'live');

        $this->postJson('/v1/data-subjects/lookup', ['email' => 'a@example.com'], $this->authed($limited))
            ->assertStatus(403);
        $this->deleteJson('/v1/data-subjects/by-email', ['email' => 'a@example.com', 'reason' => 'x'], $this->authed($limited))
            ->assertStatus(403);
    }
}
