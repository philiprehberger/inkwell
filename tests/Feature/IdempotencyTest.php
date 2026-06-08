<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_same_body_returns_cached_response(): void
    {
        [, $key] = $this->freshWorkspace();

        $body = ['name' => 'A', 'slug' => 'a', 'schema' => ['type' => 'object'], 'cors_origins' => ['https://x.com']];
        $headers = array_merge($this->authed($key), ['Idempotency-Key' => 'test-key-1']);

        $first = $this->postJson('/v1/forms', $body, $headers);
        $first->assertCreated();
        $second = $this->postJson('/v1/forms', $body, $headers);
        $second->assertCreated();
        // Same response replayed
        $this->assertSame($first->json('id'), $second->json('id'));
        $second->assertHeader('X-Inkwell-Idempotent-Replay', '1');
    }

    public function test_same_key_different_body_returns_422(): void
    {
        [, $key] = $this->freshWorkspace();
        $headers = array_merge($this->authed($key), ['Idempotency-Key' => 'test-key-2']);

        $this->postJson('/v1/forms', [
            'name' => 'A', 'slug' => 'a', 'schema' => ['type' => 'object'], 'cors_origins' => ['https://x.com'],
        ], $headers)->assertCreated();

        $this->postJson('/v1/forms', [
            'name' => 'B', 'slug' => 'b', 'schema' => ['type' => 'object'], 'cors_origins' => ['https://x.com'],
        ], $headers)->assertStatus(422);
    }
}
