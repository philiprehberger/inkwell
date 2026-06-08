<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchDestinationsJob;
use App\Models\Form;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use App\Services\SchemaValidator;
use App\Services\Spam\SpamScorer;
use App\Services\Spam\SubmissionContext;
use App\Services\Spam\SubmissionState;
use App\Services\SubmissionDedupCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The submission endpoint. **Unauthenticated by design.** Defence layers:
 *   1. Form lookup + 410 if archived.
 *   2. Body size cap (100 KB) at the Apache layer; Laravel enforces 100 KB JSON.
 *   3. CORS allowlist check.
 *   4. IP blocklist (Phase 6 wiring).
 *   5. Per-IP token bucket rate limit (60/min/IP/form default).
 *   6. JSON Schema validation (rejects 400 with field-level errors).
 *   7. SpamScorer pipeline (Phase 3) — graduated decision.
 *   8. Redis dedup (atomic SETNX, 60s window).
 *
 * Response shape depends on Accept header:
 *   - `application/json` → JSON body with `id`, `state`, optional `redirect_url`.
 *   - everything else (browser form post) → 302 redirect to thank-you URL.
 */
class IngestController extends Controller
{
    private const PER_IP_DEFAULT = 60;
    private const PER_IP_DECAY = 60;
    private const INKWELL_PRIVATE_KEYS = ['_redirect', '_subject_honeypot', '_inkwell_ts', '_inkwell_captcha'];

    public function __invoke(Request $request, string $formId): Response
    {
        $form = Form::withoutGlobalScope(WorkspaceScope::class)->find($formId);
        if (! $form) {
            return $this->problem(404, 'Form not found.');
        }
        if ($form->isArchived()) {
            return $this->problem(410, 'This form is no longer accepting submissions.');
        }

        // CORS / origin allowlist.
        $origin = $request->headers->get('Origin');
        if ($origin && ! $form->accept_any_origin) {
            $allowed = $form->cors_origins ?? [];
            if (! in_array($origin, $allowed, true)) {
                return $this->problem(403, "Origin not in this form's allowlist.");
            }
        }

        // Per-IP rate limit.
        $clientIp = $request->ip();
        $limit = self::PER_IP_DEFAULT;
        $rlKey = "ingest:{$form->id}:".$clientIp;
        if (RateLimiter::tooManyAttempts($rlKey, $limit)) {
            return $this->problem(429, 'Too many submissions from this address. Try again in a minute.');
        }
        RateLimiter::hit($rlKey, self::PER_IP_DECAY);

        // Split visitor payload from Inkwell control fields.
        $raw = $request->all();
        $userPayload = array_diff_key($raw, array_flip(self::INKWELL_PRIVATE_KEYS));
        $redirectUrl = $raw['_redirect'] ?? $form->success_redirect_url ?? null;

        // SpamScorer pipeline — composable signals, see config/inkwell.php.
        $scoreCtx = new SubmissionContext(
            form: $form,
            payload: $userPayload,
            raw: $raw,
            clientIp: $clientIp,
            userAgent: $request->userAgent(),
            referer: $request->headers->get('Referer'),
            renderedAtTimestamp: isset($raw['_inkwell_ts']) ? (int) $raw['_inkwell_ts'] : null,
            captchaToken: is_string($raw['_inkwell_captcha'] ?? null) ? $raw['_inkwell_captcha'] : null,
        );
        $scoreResult = SpamScorer::fromConfig()->score($scoreCtx);
        $state = match ($scoreResult->state) {
            SubmissionState::REJECTED => Submission::STATE_REJECTED,
            SubmissionState::SPAM => Submission::STATE_SPAM,
            SubmissionState::QUARANTINED => Submission::STATE_QUARANTINED,
            default => Submission::STATE_CLEAN,
        };
        $score = $scoreResult->score;
        $signals = $scoreResult->toJson();

        // Schema validation only for non-rejected submissions.
        if ($state !== Submission::STATE_REJECTED) {
            $validation = SchemaValidator::validate($userPayload, $form->schema ?: []);
            if (! $validation['ok']) {
                return $this->problem(400, 'The submission failed schema validation.', $validation['errors']);
            }
        }

        // For rejected (hard-block) submissions we store the metadata but
        // discard the payload — counts against rate-limit budgets without
        // accumulating PII for hard-blocked spam.
        $hash = SubmissionDedupCache::canonicalHash($userPayload);
        $submission = Submission::withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $form->workspace_id,
            'form_id' => $form->id,
            'payload' => $state === Submission::STATE_REJECTED ? [] : $userPayload,
            'meta' => [
                'user_agent' => $request->userAgent(),
                'referer' => $request->headers->get('Referer'),
                'client_ip' => $clientIp,
            ],
            'spam_score' => $score,
            'spam_signals' => $signals,
            'state' => $state,
            'payload_hash' => $hash,
        ]);

        $duplicateOf = SubmissionDedupCache::claim($form, $hash, $submission->id);
        $headers = [];
        $finalId = $submission->id;
        $finalState = $submission->state;
        if ($duplicateOf !== null) {
            // Earlier submission won the race; delete the new one and reply
            // with the earlier ID. Visitor-friendly silent dedup.
            $submission->delete();
            $finalId = $duplicateOf;
            $headers['X-Inkwell-Duplicate'] = '1';
            $original = Submission::withoutGlobalScope(WorkspaceScope::class)->find($duplicateOf);
            $finalState = $original?->state ?? $finalState;
        }

        // Destination fan-out — for CLEAN submissions only. Spam / quarantined
        // submissions wait for buyer's promote action; rejected store no PII.
        if ($finalState === Submission::STATE_CLEAN) {
            DispatchDestinationsJob::dispatch($finalId);
        }

        if ($this->wantsJson($request)) {
            return response()->json([
                'id' => $finalId,
                'state' => $finalState,
                'redirect_url' => $redirectUrl,
            ], 200, $headers);
        }

        $target = $redirectUrl ?: route('v1.hosted-thank-you', ['id' => $finalId]);
        return new RedirectResponse($target, 302, $headers);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = $request->headers->get('Accept', '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        return $request->getContentTypeFormat() === 'json';
    }

    private function problem(int $status, string $detail, ?array $errors = null): JsonResponse
    {
        $body = [
            'type' => 'about:blank',
            'title' => match (true) {
                $status === 404 => 'Not found',
                $status === 403 => 'Forbidden',
                $status === 410 => 'Form archived',
                $status === 429 => 'Too many requests',
                $status === 400 => 'Invalid request',
                default => 'Error',
            },
            'status' => $status,
            'detail' => $detail,
        ];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        return response()->json($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}
