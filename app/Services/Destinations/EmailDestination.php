<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Email destination — forwards the submission to one or more buyer-supplied
 * addresses.
 *
 * Sender hardening:
 *   - Always sends from `noreply@inkwell.philiprehberger.com`. Submitter email
 *     goes in body + Reply-To only — buyer can reply directly without leaking
 *     their address as From.
 *   - All submitter-controlled values pass through Header::sanitize() before
 *     touching Subject / Reply-To. CRLF / control chars stripped.
 */
final class EmailDestination implements Destination
{
    public function kind(): string
    {
        return FormDestination::KIND_EMAIL;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $errors = [];
        $to = $config['to'] ?? null;
        if (! is_array($to) || $to === []) {
            $errors['to'] = ['A non-empty array of recipient addresses is required.'];
        } else {
            foreach ($to as $i => $addr) {
                if (! is_string($addr) || ! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $errors['to'][] = "to[{$i}] is not a valid email address.";
                }
            }
        }
        return $errors === [] ? ConfigValidation::ok() : ConfigValidation::fail($errors);
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $to = (array) ($config['to'] ?? []);
        if ($to === []) {
            return AttemptResult::failed('email: no recipients', 'config_missing_to');
        }

        $payload = $submission->payload ?: [];
        $submitterEmail = $payload['email'] ?? null;
        $subject = Header::sanitize($config['subject_template'] ?? 'New submission via Inkwell', 200);

        $textLines = ['New form submission:', ''];
        foreach ($payload as $key => $value) {
            $line = is_array($value) ? json_encode($value) : (string) $value;
            $textLines[] = $key.': '.$line;
        }
        $textLines[] = '';
        $textLines[] = '— Sent via Inkwell';
        $body = implode("\n", $textLines);

        try {
            $start = microtime(true);
            Mail::raw($body, function ($message) use ($to, $subject, $submitterEmail) {
                $message->from('noreply@inkwell.philiprehberger.com', 'Inkwell');
                $message->subject($subject);
                $message->to($to);
                if (is_string($submitterEmail) && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
                    $message->replyTo(Header::sanitize($submitterEmail, 100));
                }
            });
            $latency = (int) ((microtime(true) - $start) * 1000);
            return AttemptResult::sent('email to '.count($to).' recipients', 250, null, $latency);
        } catch (\Throwable $e) {
            Log::warning('email destination error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('email send failed: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        return $v->valid
            ? new HealthResult(HealthResult::HEALTHY)
            : new HealthResult(HealthResult::FAILED, implode('; ', array_merge(...array_values($v->errors))));
    }
}
