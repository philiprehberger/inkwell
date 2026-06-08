<?php

namespace Tests;

use App\Models\ApiKey;
use App\Models\Form;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return array{0: Workspace, 1: string} [workspace, admin-key plaintext] */
    protected function freshWorkspace(string $name = 'Test Workspace'): array
    {
        $workspace = Workspace::create(['name' => $name, 'slug' => str()->slug($name).'-'.uniqid()]);
        [, $plaintext] = ApiKey::mint($workspace, ['admin'], 'live');
        return [$workspace, $plaintext];
    }

    protected function makeForm(Workspace $workspace, array $overrides = []): Form
    {
        return Form::withoutGlobalScope(\App\Models\Scopes\WorkspaceScope::class)->create(array_merge([
            'workspace_id' => $workspace->id,
            'slug' => 'contact-'.uniqid(),
            'name' => 'Contact',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['name', 'email', 'message'],
            ],
            'spam_threshold' => 50,
            'cors_origins' => ['https://example.com'],
            'accept_any_origin' => false,
        ], $overrides));
    }

    /** @return array<string, string> */
    protected function authed(string $key): array
    {
        return ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'];
    }
}
