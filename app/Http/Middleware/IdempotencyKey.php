<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProblemResponse;
use App\Models\IdempotencyRecord;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe-style idempotency. Honoured on every mutating management endpoint
 * that opts in (Route::middleware('idempotency')->...).
 *
 * Behaviour:
 *   - Same key + same body within 24h → replay the cached response (same body + status).
 *   - Same key + different body → 422 problem+json with type `idempotency_key_conflict`.
 *   - No key → request passes through unchanged.
 *
 * Records live in the `idempotency_records` table with a sweep job (Phase 6)
 * pruning expired rows.
 */
class IdempotencyKey
{
    private const TTL_HOURS = 24;
    private const RESPONSE_BODY_BYTE_CAP = 65536;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null || $key === '') {
            return $next($request);
        }

        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');
        if ($workspace === null) {
            return $next($request);
        }

        $bodyHash = hash('sha256', $request->getContent() ?: json_encode($request->all()) ?: '');

        $existing = IdempotencyRecord::withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->id)
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            if ($existing->body_hash !== $bodyHash) {
                return new ProblemResponse(
                    status: 422,
                    title: 'Idempotency key conflict',
                    type: 'about:blank#idempotency_key_conflict',
                    detail: 'This Idempotency-Key was used with a different request body within the 24-hour window.',
                );
            }
            return new JsonResponse($existing->response, $existing->status_code, ['X-Inkwell-Idempotent-Replay' => '1']);
        }

        /** @var Response $response */
        $response = $next($request);

        // Cache 2xx responses only. 4xx/5xx aren't worth replaying.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $body = $response->getContent() ?: '';
        if (strlen($body) > self::RESPONSE_BODY_BYTE_CAP) {
            return $response;
        }
        $decoded = json_decode($body, associative: true);

        IdempotencyRecord::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspace->id,
            'key' => $key,
            'body_hash' => $bodyHash,
            'response' => is_array($decoded) ? $decoded : ['raw' => $body],
            'status_code' => $response->getStatusCode(),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return $response;
    }
}
