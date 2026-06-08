<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_with_explicit_cors_origins(): void
    {
        [, $key] = $this->freshWorkspace();

        $resp = $this->postJson('/v1/forms', [
            'name' => 'Contact Us',
            'slug' => 'contact-us',
            'schema' => ['type' => 'object'],
            'cors_origins' => ['https://example.com'],
        ], $this->authed($key));

        $resp->assertCreated();
        $resp->assertJsonPath('slug', 'contact-us');
        $resp->assertJsonPath('accept_any_origin', false);
    }

    public function test_create_form_without_cors_or_accept_any_is_rejected(): void
    {
        [, $key] = $this->freshWorkspace();

        $this->postJson('/v1/forms', [
            'name' => 'Bad Form',
            'slug' => 'bad',
            'schema' => ['type' => 'object'],
        ], $this->authed($key))->assertStatus(400);
    }

    public function test_create_form_with_accept_any_origin(): void
    {
        [, $key] = $this->freshWorkspace();

        $this->postJson('/v1/forms', [
            'name' => 'Open Form',
            'slug' => 'open',
            'schema' => ['type' => 'object'],
            'accept_any_origin' => true,
        ], $this->authed($key))->assertCreated();
    }

    public function test_duplicate_slug_409(): void
    {
        [, $key] = $this->freshWorkspace();

        $payload = [
            'name' => 'A',
            'slug' => 'dup',
            'schema' => ['type' => 'object'],
            'cors_origins' => ['https://x.com'],
        ];
        $this->postJson('/v1/forms', $payload, $this->authed($key))->assertCreated();
        $this->postJson('/v1/forms', $payload, $this->authed($key))->assertStatus(409);
    }

    public function test_list_forms(): void
    {
        [, $key] = $this->freshWorkspace();
        $this->postJson('/v1/forms', [
            'name' => 'A', 'slug' => 'a', 'schema' => ['type' => 'object'], 'cors_origins' => ['https://x.com'],
        ], $this->authed($key));

        $this->getJson('/v1/forms', $this->authed($key))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_archive_form_returns_410_on_submission(): void
    {
        [$workspace, $key] = $this->freshWorkspace();
        $form = $this->makeForm($workspace);

        $this->deleteJson("/v1/forms/{$form->id}", [], $this->authed($key))->assertNoContent();

        $this->postJson("/v1/forms/{$form->id}/submit", [], [
            'Accept' => 'application/json',
        ])->assertStatus(410);
    }

    public function test_unauthenticated_management_request_returns_401(): void
    {
        $this->getJson('/v1/forms')->assertStatus(401);
    }
}
