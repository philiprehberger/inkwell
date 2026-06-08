<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemResponse;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-workspace token bucket. Sits after ApiKeyAuth so the workspace is
 * already attached to the request. Emits X-RateLimit-* headers on every
 * response and returns 429 problem+json on overage.
 *
 * Default: 100 requests per minute per workspace. Override per-workspace
 * later via a column on the workspaces table when tenancy ships.
 */
class WorkspaceRateLimit
{
    private const DEFAULT_PER_MINUTE = 100;
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');

        if ($workspace === null) {
            return $next($request);
        }

        $limit = self::DEFAULT_PER_MINUTE;
        $key = "wsr:{$workspace->id}";

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            $response = new ProblemResponse(
                status: 429,
                title: 'Too many requests',
                detail: "Workspace rate limit of {$limit} requests per minute exceeded.",
            );

            return $this->withHeaders($response, $limit, 0, now()->addSeconds($retryAfter)->timestamp);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $remaining = max(0, $limit - RateLimiter::attempts($key));
        $resetAt = now()->addSeconds(self::DECAY_SECONDS)->timestamp;

        $response = $next($request);

        return $this->withHeaders($response, $limit, $remaining, $resetAt);
    }

    private function withHeaders(Response $response, int $limit, int $remaining, int $resetAt): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $resetAt);

        return $response;
    }
}
