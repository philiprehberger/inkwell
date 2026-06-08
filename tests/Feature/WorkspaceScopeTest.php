<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_a_cannot_see_workspace_b_forms(): void
    {
        [$wA, $keyA] = $this->freshWorkspace('Alpha');
        [$wB, $keyB] = $this->freshWorkspace('Bravo');

        $this->makeForm($wB, ['slug' => 'b-form']);

        $resp = $this->getJson('/v1/forms', $this->authed($keyA));
        $resp->assertOk();
        $resp->assertJsonCount(0, 'data');
    }

    public function test_workspace_a_404s_on_workspace_b_form_id(): void
    {
        [$wA, $keyA] = $this->freshWorkspace('Alpha');
        [$wB] = $this->freshWorkspace('Bravo');

        $bForm = $this->makeForm($wB);
        $this->getJson("/v1/forms/{$bForm->id}", $this->authed($keyA))->assertStatus(404);
    }
}
