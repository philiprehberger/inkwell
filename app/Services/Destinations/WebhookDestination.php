<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use App\Services\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Webhook destination — POSTs the submission JSON to a buyer-supplied URL with
 * an HMAC-SHA256 signature header (Stripe/Svix-style `t=…,v1=…`).
 *
 * Secret rotation: when previous_secret + previous_secret_expires_at are set
 * on the FormDestination row, requests include `X-Inkwell-Signature-Old`
 * computed with the old secret, alongside `X-Inkwell-Signature` with the new
 * one. 48-hour grace window in Phase 4; the rotation flow lives on the
 * DestinationsController (already wired in Phase 2).
 *
 * SSRF guard refuses private / loopback / link-local destinations.
 */
final class WebhookDestination implements Destination
{
    private const TIMEOUT_SECONDS = 10;

    public function kind(): string
    {
        return FormDestination::KIND_WEBHOOK;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $errors = [];
        $url = $config['url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors['url'] = ['A valid HTTPS URL is required.'];
        } elseif (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            $errors['url'] = ['URL must use http:// or https:// scheme.'];
        }
        if (! isset($config['secret']) || ! is_string($config['secret']) || strlen($config['secret']) < 16) {
            $errors['secret'] = ['Webhook secret of at least 16 characters is required.'];
        }
        return $errors === [] ? ConfigValidation::ok() : ConfigValidation::fail($errors);
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $url = $config['url'] ?? null;
        $secret = $config['secret'] ?? null;
        if (! is_string($url) || ! is_string($secret)) {
            return AttemptResult::failed('webhook config missing', 'config_invalid');
        }

        // SSRF guard.
        try {
            SsrfGuard::assertSafeUrl($url);
        } catch (\Throwable $e) {
            return AttemptResult::failed('SSRF guard refused destination URL', 'ssrf_blocked');
        }

        $body = json_encode([
            'id' => $submission->id,
            'form_id' => $submission->form_id,
            'state' => $submission->state,
            'spam_score' => $submission->spam_score,
            'payload' => $submission->payload,
            'meta' => $submission->meta,
            'created_at' => $submission->created_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $sig = $this->sign($timestamp, $body, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Inkwell-Signature' => "t={$timestamp},v1={$sig}",
            'User-Agent' => 'Inkwell-Webhook/1.0',
        ];

        if (is_string($destination->previous_secret ?? null)
            && $destination->previous_secret_expires_at !== null
            && $destination->previous_secret_expires_at->isFuture()) {
            $oldSig = $this->sign($timestamp, $body, (string) $destination->previous_secret);
            $headers['X-Inkwell-Signature-Old'] = "t={$timestamp},v1={$oldSig}";
        }

        try {
            $start = microtime(true);
            $response = Http::withHeaders($headers)->timeout(self::TIMEOUT_SECONDS)->withBody($body, 'application/json')->post($url);
            $latency = (int) ((microtime(true) - $start) * 1000);
            $snippet = substr((string) $response->body(), 0, 4096);
            if ($response->successful()) {
                return AttemptResult::sent("POST {$url}", $response->status(), $snippet, $latency);
            }
            return AttemptResult::failed("POST {$url} returned {$response->status()}", 'http_error', $response->status(), $snippet, $latency);
        } catch (\Throwable $e) {
            Log::warning('webhook delivery error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('webhook transport error: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        if (! $v->valid) {
            return new HealthResult(HealthResult::FAILED, implode('; ', array_merge(...array_values($v->errors))));
        }
        // Phase 5: ping the destination URL with a HEAD or a no-op POST to
        // verify reachability. For now config-valid = healthy.
        return new HealthResult(HealthResult::HEALTHY);
    }

    private function sign(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
