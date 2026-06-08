<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack destination — POSTs a Block Kit message to an Incoming Webhook URL.
 * Length limits: 40,000 chars total, 50 blocks. We trim aggressively.
 */
final class SlackDestination implements Destination
{
    private const BLOCK_TEXT_LIMIT = 3000;

    public function kind(): string
    {
        return FormDestination::KIND_SLACK;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $url = $config['webhook_url'] ?? null;
        if (! is_string($url) || ! preg_match('#^https://hooks\.slack\.com/services/[^\s]+#', $url)) {
            return ConfigValidation::fail(['webhook_url' => ['Slack incoming webhook URL required (hooks.slack.com/services/...).']]);
        }
        return ConfigValidation::ok();
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $url = (string) ($config['webhook_url'] ?? '');
        if ($url === '') {
            return AttemptResult::failed('slack: no webhook_url', 'config_missing_url');
        }
        $body = $this->buildBlockKit($submission);

        try {
            $start = microtime(true);
            $response = Http::asJson()->timeout(10)->post($url, $body);
            $latency = (int) ((microtime(true) - $start) * 1000);
            $snippet = substr((string) $response->body(), 0, 1024);
            return $response->successful()
                ? AttemptResult::sent('slack message posted', $response->status(), $snippet, $latency)
                : AttemptResult::failed("slack returned {$response->status()}", 'http_error', $response->status(), $snippet, $latency);
        } catch (\Throwable $e) {
            Log::warning('slack delivery error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('slack transport error: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        return $v->valid ? new HealthResult(HealthResult::HEALTHY) : new HealthResult(HealthResult::FAILED, 'config invalid');
    }

    private function buildBlockKit(Submission $submission): array
    {
        $payload = $submission->payload ?: [];
        $lines = [];
        foreach ($payload as $key => $value) {
            $val = is_array($value) ? json_encode($value) : (string) $value;
            $lines[] = '*'.$key.':* '.mb_substr($val, 0, 400);
        }
        $text = mb_substr(implode("\n", $lines), 0, self::BLOCK_TEXT_LIMIT);
        return [
            'text' => 'New form submission',
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*New submission*  \nSubmission ID: `{$submission->id}`"]],
                ['type' => 'divider'],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
            ],
        ];
    }
}
