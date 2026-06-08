<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeysApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mint_key_returns_plaintext_once(): void
    {
        [, $key] = $this->freshWorkspace();

        $resp = $this->postJson('/v1/api-keys', [
            'name' => 'CI',
            'scopes' => ['forms.read', 'submissions.write'],
        ], $this->authed($key));

        $resp->assertCreated();
        $resp->assertJsonStructure(['id', 'secret', 'prefix', 'last_four']);
        $this->assertStringStartsWith('inkwell_live_', $resp->json('secret'));
    }

    public function test_non_admin_key_cannot_mint_keys(): void
    {
        [$workspace] = $this->freshWorkspace();
        [, $limited] = ApiKey::mint($workspace, ['forms.read'], 'live');

        $this->postJson('/v1/api-keys', ['scopes' => ['admin']], $this->authed($limited))
            ->assertStatus(403);
    }

    public function test_revoked_key_unusable(): void
    {
        [, $key] = $this->freshWorkspace();
        $resp = $this->postJson('/v1/api-keys', ['scopes' => ['admin']], $this->authed($key));
        $id = $resp->json('id');
        $secret = $resp->json('secret');

        $this->deleteJson("/v1/api-keys/{$id}", [], $this->authed($key))->assertNoContent();
        $this->getJson('/v1/forms', $this->authed($secret))->assertStatus(401);
    }
}
