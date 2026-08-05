<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\SignatureSchemeUsage;
use App\Models\Submission;
use App\Services\Destinations\Security\EgressPolicy;
use App\Services\Destinations\Security\HeaderPolicy;
use App\Services\SsrfGuard;
use PhilipRehberger\Interchange\Http\PropagatingHttpClient;
use PhilipRehberger\Interchange\Signing\StandardWebhooksScheme;
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

        // Plan 5.2 — custom headers are allowlisted, bounded, and injection-checked.
        $headers = $config['headers'] ?? [];
        if ($headers !== [] && ! is_array($headers)) {
            $errors['headers'] = ['Headers must be a name/value map.'];
        } elseif (is_array($headers) && $headers !== []) {
            $headerErrors = (new HeaderPolicy)->validate($headers);
            if ($headerErrors !== []) {
                $errors['headers'] = $headerErrors;
            }

            // Plan 5.3 — a credentialed destination must name where it may go.
            if (is_string($url)) {
                $egressErrors = (new EgressPolicy)->validate($config['workspace'] ?? null, $url, $headers);
                if ($egressErrors !== []) {
                    $errors['egress'] = $egressErrors;
                }
            }
        }

        if (isset($config['envelope_shape']) && EnvelopeShape::tryFrom((string) $config['envelope_shape']) === null) {
            $errors['envelope_shape'] = ['Unknown envelope shape. Allowed: inkwell-native, webhook-relay, flat.'];
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

        // Plan 5.5 — a fixed shape, never a tenant-authored template. The
        // default reproduces the pre-adoption envelope byte for byte; the
        // golden regression (T-5.2) is what proves it.
        $shape = $destination->envelope_shape instanceof EnvelopeShape
            ? $destination->envelope_shape
            : EnvelopeShape::tryFrom((string) ($destination->envelope_shape ?? '')) ?? EnvelopeShape::InkwellNative;

        $body = json_encode($shape->build($submission), JSON_UNESCAPED_SLASHES);
        // now() rather than time(): identical in production, but freezable, which
        // is what makes the D-3 golden regression checkable at all.
        $timestamp = now()->getTimestamp();

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Inkwell-Webhook/1.0',
        ];

        // Plan 5.9 — scheme selection. `inkwell-v0` MUST stay byte-identical:
        // existing consumers hold secrets and verify these signatures, and a
        // change they did not agree to is a broken integration.
        $scheme = (string) ($destination->signature_scheme ?? 'inkwell-v0');

        if ($scheme === 'standard-webhooks') {
            $standard = new StandardWebhooksScheme;
            $secrets = [$secret];

            if ($this->rotationActive($destination)) {
                $secrets[] = (string) $destination->previous_secret;
            }

            // Rotation is a space-delimited list in the ONE header, not a
            // second header the way inkwell-v0 does it.
            $headers += $standard->signWithRotation($submission->id, $body, $secrets, $timestamp);
        } else {
            $sig = $this->sign($timestamp, $body, $secret);
            $headers['X-Inkwell-Signature'] = "t={$timestamp},v1={$sig}";

            if ($this->rotationActive($destination)) {
                $oldSig = $this->sign($timestamp, $body, (string) $destination->previous_secret);
                $headers['X-Inkwell-Signature-Old'] = "t={$timestamp},v1={$oldSig}";
            }
        }

        // Plan 5.2 — tenant headers are applied last but may never override a
        // signature or the content type: an allowlisted name that collided
        // with one of ours would let a tenant forge or strip a signature.
        foreach ($this->tenantHeaders($destination) as $name => $value) {
            if (! array_key_exists($name, $headers)) {
                $headers[$name] = $value;
            }
        }

        // Plan 5.11 — outbound trace context, so the delivery joins the trace
        // rather than starting a new one.
        $headers += PropagatingHttpClient::headers();

        // Plan D-8 — durable evidence of which scheme was actually used, so
        // the 13.1 sunset decision does not rest on log retention.
        SignatureSchemeUsage::record($destination->id, $scheme);

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

    private function rotationActive(FormDestination $destination): bool
    {
        return is_string($destination->previous_secret ?? null)
            && $destination->previous_secret_expires_at !== null
            && $destination->previous_secret_expires_at->isFuture();
    }

    /** @return array<string, string> */
    private function tenantHeaders(FormDestination $destination): array
    {
        $headers = $destination->headers;

        if (! is_array($headers) || $headers === []) {
            return [];
        }

        $policy = new HeaderPolicy;
        $out = [];

        foreach ($headers as $name => $value) {
            // Re-check at send time, not only at save time: the allowlist may
            // have tightened since the destination was created.
            if (is_string($value) && $policy->isAllowed((string) $name)) {
                $out[(string) $name] = $value;
            }
        }

        return $out;
    }

    private function sign(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
