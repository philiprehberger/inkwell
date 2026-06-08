<?php

namespace Tests\Feature;

use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_submission_accepted_returns_json_for_json_client(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $resp = $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'message' => 'Hello',
        ], ['Origin' => 'https://example.com']);

        $resp->assertOk();
        $resp->assertJsonPath('state', 'clean');
        $this->assertNotNull($resp->json('id'));
    }

    public function test_form_post_redirects(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace, ['success_redirect_url' => 'https://example.com/thanks']);

        $resp = $this->post("/v1/forms/{$form->id}/submit", [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'message' => 'Hello',
        ], ['Origin' => 'https://example.com']);

        $resp->assertRedirect('https://example.com/thanks');
    }

    public function test_schema_validation_failure_returns_400(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Alice',
            // missing required email + message
        ], ['Origin' => 'https://example.com'])->assertStatus(400);
    }

    public function test_honeypot_filled_marks_rejected_without_storing_payload(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'A',
            'email' => 'a@example.com',
            'message' => 'M',
            '_subject_honeypot' => 'spam-spam-spam',
        ], ['Origin' => 'https://example.com'])->assertOk();

        $submission = Submission::withoutGlobalScope(\App\Models\Scopes\WorkspaceScope::class)
            ->where('form_id', $form->id)->first();
        $this->assertSame('rejected', $submission->state);
        $this->assertSame([], $submission->payload);
    }

    public function test_origin_not_in_allowlist_returns_403(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace, [
            'cors_origins' => ['https://other.example'],
            'accept_any_origin' => false,
        ]);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'A', 'email' => 'a@example.com', 'message' => 'M',
        ], ['Origin' => 'https://intruder.example'])->assertStatus(403);
    }

    public function test_dedup_returns_original_submission_id(): void
    {
        [$workspace] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $payload = ['name' => 'A', 'email' => 'a@example.com', 'message' => 'M'];

        $first = $this->postJson("/v1/forms/{$form->id}/submit", $payload, [
            'Origin' => 'https://example.com',
        ])->assertOk();
        $firstId = $first->json('id');

        $second = $this->postJson("/v1/forms/{$form->id}/submit", $payload, [
            'Origin' => 'https://example.com',
        ])->assertOk();
        $this->assertSame($firstId, $second->json('id'));
    }
}
