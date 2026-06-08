<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemResponse;
use App\Models\ApiKey;
use App\Models\Scopes\WorkspaceScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    /**
     * @param  array<string>  $scopes  required scopes for the route (optional).
     */
    public function handle(Request $request, Closure $next, ...$scopes): Response
    {
        $token = $this->extractBearer($request);

        if ($token === null) {
            return new ProblemResponse(
                status: 401,
                title: 'Authentication required',
                detail: 'Provide an Authorization: Bearer inkwell_live_... or inkwell_test_... header.',
            );
        }

        $apiKey = ApiKey::findByPlaintext($token);

        if ($apiKey === null) {
            return new ProblemResponse(
                status: 401,
                title: 'Authentication required',
                detail: 'The API key is invalid or has been revoked.',
            );
        }

        if ($scopes !== []) {
            $missing = array_filter($scopes, fn ($s) => ! $apiKey->hasScope($s));
            if ($missing !== []) {
                return new ProblemResponse(
                    status: 403,
                    title: 'Forbidden',
                    detail: 'This endpoint requires scope: '.implode(', ', $missing),
                );
            }
        }

        $workspace = $apiKey->workspace()->withoutGlobalScope(WorkspaceScope::class)->first();
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('workspace', $workspace);

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');

        if (! is_string($header) || $header === '') {
            return null;
        }

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
