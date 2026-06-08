<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mailchimp destination — adds the submitter's email to an audience with optional
 * tags + merge-field mapping.
 *
 * Config shape:
 *   api_key            string  required (Mailchimp API key — datacenter suffix included)
 *   audience_id        string  required
 *   tags               array   list of tags to apply
 *   double_opt_in      bool    default false (pending state vs subscribed)
 *   merge_field_mapping  array  form_field => mailchimp_merge_tag (e.g. FNAME, LNAME)
 */
final class MailchimpDestination implements Destination
{
    public function kind(): string
    {
        return FormDestination::KIND_MAILCHIMP;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $errors = [];
        if (empty($config['api_key']) || ! is_string($config['api_key']) || ! str_contains($config['api_key'], '-')) {
            $errors['api_key'] = ['Mailchimp API key required (format: key-dcN).'];
        }
        if (empty($config['audience_id']) || ! is_string($config['audience_id'])) {
            $errors['audience_id'] = ['audience_id required.'];
        }
        return $errors === [] ? ConfigValidation::ok() : ConfigValidation::fail($errors);
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $apiKey = $config['api_key'] ?? null;
        $audienceId = $config['audience_id'] ?? null;
        if (! is_string($apiKey) || ! is_string($audienceId)) {
            return AttemptResult::failed('mailchimp: missing api_key or audience_id', 'config_invalid');
        }

        // Datacenter = suffix after the dash, e.g. "abc-us21" → "us21".
        $datacenter = substr(strrchr($apiKey, '-'), 1);
        if ($datacenter === false || $datacenter === '') {
            return AttemptResult::failed('mailchimp: invalid api_key format', 'config_invalid');
        }

        $payload = $submission->payload ?: [];
        $email = $payload['email'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return AttemptResult::failed('mailchimp: submission has no usable email', 'missing_email');
        }

        $mergeFields = $this->mapMergeFields($payload, $config['merge_field_mapping'] ?? []);
        $tags = (array) ($config['tags'] ?? []);
        $status = ($config['double_opt_in'] ?? false) === true ? 'pending' : 'subscribed';

        $url = "https://{$datacenter}.api.mailchimp.com/3.0/lists/{$audienceId}/members/".md5(strtolower($email));

        try {
            $start = microtime(true);
            $response = Http::withBasicAuth('anystring', $apiKey)->timeout(15)
                ->put($url, [
                    'email_address' => $email,
                    'status_if_new' => $status,
                    'merge_fields' => $mergeFields,
                    'tags' => $tags,
                ]);
            $latency = (int) ((microtime(true) - $start) * 1000);
            if ($response->status() === 401 || $response->status() === 403) {
                return AttemptResult::failed('mailchimp: auth failed', 'oauth_expired', $response->status(), null, $latency);
            }
            if ($response->successful()) {
                return AttemptResult::sent("mailchimp member upserted in audience {$audienceId}", $response->status(), null, $latency);
            }
            return AttemptResult::failed("mailchimp returned {$response->status()}", 'http_error', $response->status(), substr($response->body(), 0, 1024), $latency);
        } catch (\Throwable $e) {
            Log::warning('mailchimp delivery error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('mailchimp transport error: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        return $v->valid ? new HealthResult(HealthResult::HEALTHY) : new HealthResult(HealthResult::FAILED, 'config invalid');
    }

    private function mapMergeFields(array $payload, array $mapping): array
    {
        if ($mapping !== []) {
            $out = [];
            foreach ($mapping as $formField => $mergeTag) {
                if (isset($payload[$formField]) && is_string($mergeTag)) {
                    $out[$mergeTag] = is_array($payload[$formField]) ? json_encode($payload[$formField]) : (string) $payload[$formField];
                }
            }
            return $out;
        }
        // Default: map common fields to Mailchimp defaults
        $out = [];
        if (isset($payload['name']) && is_string($payload['name'])) {
            $parts = explode(' ', trim($payload['name']), 2);
            $out['FNAME'] = $parts[0] ?? '';
            if (isset($parts[1])) $out['LNAME'] = $parts[1];
        }
        if (isset($payload['phone'])) $out['PHONE'] = (string) $payload['phone'];
        return $out;
    }
}
