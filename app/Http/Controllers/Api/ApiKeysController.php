<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApiKeysController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $keys = $workspace->apiKeys()->orderByDesc('created_at')->get();
        return response()->json(['data' => $keys->map(fn ($k) => $this->serialize($k))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        $data = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:forms.read,forms.write,submissions.read,submissions.write,destinations.write,admin'],
        ])->validate();

        [$apiKey, $plaintext] = ApiKey::mint(
            workspace: $workspace,
            scopes: $data['scopes'],
            env: 'live',
            name: $data['name'] ?? null,
        );

        AuditLogger::record($workspace, 'api_key', $apiKey->id, 'created', ['scopes' => $apiKey->scopes], request: $request);

        $payload = $this->serialize($apiKey);
        $payload['secret'] = $plaintext;
        return response()->json($payload, 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $key = $workspace->apiKeys()->findOrFail($id);
        $key->revoked_at = now();
        $key->save();
        AuditLogger::record($workspace, 'api_key', $id, 'revoked', request: $request);
        return response()->json(status: 204);
    }

    private function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }

    private function serialize(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'last_four' => $key->last_four,
            'scopes' => $key->scopes ?: [],
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }
}
