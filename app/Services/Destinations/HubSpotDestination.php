<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HubSpot destination — upserts a contact (by email) and sets custom properties.
 *
 * Config shape:
 *   access_token       string  required (OAuth or Private App token)
 *   property_mapping   array   form_field => hubspot_property_internal_name
 *
 * Default contact mapping (overridable):
 *   email   -> email
 *   name    -> firstname / lastname (split on first space)
 *   phone   -> phone
 *   message -> notes (custom property)
 */
final class HubSpotDestination implements Destination
{
    private const API_BASE = 'https://api.hubapi.com';

    public function kind(): string
    {
        return FormDestination::KIND_HUBSPOT;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $errors = [];
        if (empty($config['access_token']) || ! is_string($config['access_token'])) {
            $errors['access_token'] = ['HubSpot access token (OAuth or Private App) required.'];
        }
        return $errors === [] ? ConfigValidation::ok() : ConfigValidation::fail($errors);
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $token = $config['access_token'] ?? null;
        if (! is_string($token)) {
            return AttemptResult::failed('hubspot: missing access_token', 'config_invalid');
        }

        $payload = $submission->payload ?: [];
        $properties = $this->mapProperties($payload, $config['property_mapping'] ?? []);

        $email = $properties['email'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return AttemptResult::failed('hubspot: submission has no usable email; cannot upsert contact', 'missing_email');
        }

        try {
            $start = microtime(true);
            $response = Http::withToken($token)->timeout(15)
                ->put("self::API_BASE/crm/v3/objects/contacts/{$email}?idProperty=email", [
                    'properties' => $properties,
                ]);
            $latency = (int) ((microtime(true) - $start) * 1000);
            if ($response->status() === 401) {
                return AttemptResult::failed('hubspot: 401 — token expired or revoked', 'oauth_expired', 401, null, $latency);
            }
            if ($response->successful() || $response->status() === 404) {
                // 404 from PUT-with-idProperty means "contact not found, create it".
                if ($response->status() === 404) {
                    $createResp = Http::withToken($token)->timeout(15)
                        ->post(self::API_BASE.'/crm/v3/objects/contacts', ['properties' => $properties]);
                    if (! $createResp->successful()) {
                        return AttemptResult::failed("hubspot create returned {$createResp->status()}", 'http_error', $createResp->status(), substr($createResp->body(), 0, 1024), $latency);
                    }
                    return AttemptResult::sent('hubspot contact created', $createResp->status(), null, $latency);
                }
                return AttemptResult::sent('hubspot contact upserted', $response->status(), null, $latency);
            }
            return AttemptResult::failed("hubspot returned {$response->status()}", 'http_error', $response->status(), substr($response->body(), 0, 1024), $latency);
        } catch (\Throwable $e) {
            Log::warning('hubspot delivery error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('hubspot transport error: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        return $v->valid ? new HealthResult(HealthResult::HEALTHY) : new HealthResult(HealthResult::FAILED, 'config invalid');
    }

    private function mapProperties(array $payload, array $mapping): array
    {
        if ($mapping !== []) {
            $out = [];
            foreach ($mapping as $formField => $hubspotProp) {
                if (isset($payload[$formField]) && is_string($hubspotProp)) {
                    $out[$hubspotProp] = is_array($payload[$formField]) ? json_encode($payload[$formField]) : (string) $payload[$formField];
                }
            }
            return $out;
        }
        // Default mapping
        $out = [];
        if (isset($payload['email'])) $out['email'] = (string) $payload['email'];
        if (isset($payload['phone'])) $out['phone'] = (string) $payload['phone'];
        if (isset($payload['name']) && is_string($payload['name'])) {
            $parts = explode(' ', trim($payload['name']), 2);
            $out['firstname'] = $parts[0] ?? '';
            if (isset($parts[1])) $out['lastname'] = $parts[1];
        }
        if (isset($payload['message'])) $out['notes_last_contacted'] = (string) $payload['message'];
        return $out;
    }
}
