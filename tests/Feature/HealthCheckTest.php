<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_returns_200_with_status_payload(): void
    {
        $response = $this->getJson('/v1/healthz');

        $response->assertOk();
        $response->assertJsonStructure(['status', 'version']);
        $response->assertJsonPath('status', 'healthy');
    }

    public function test_healthz_does_not_require_auth(): void
    {
        $this->getJson('/v1/healthz')->assertOk();
    }
}
