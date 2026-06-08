<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearer($request);

        if ($token === null) {
            return new ProblemResponse(
                status: 401,
                title: 'Authentication required',
                detail: 'Provide an Authorization: Bearer whk_live_... or whk_test_... header.',
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

        // Attach the resolved key + workspace so controllers can read them.
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('workspace', $apiKey->workspace);

        // Fire-and-forget update — inline for now, queue when Horizon lands.
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
