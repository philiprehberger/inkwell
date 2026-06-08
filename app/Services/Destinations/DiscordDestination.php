<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discord destination — POSTs an embed to a Discord webhook URL.
 * Limits: 6,000 chars per embed, 25 fields per embed.
 */
final class DiscordDestination implements Destination
{
    private const EMBED_VALUE_LIMIT = 1024;
    private const MAX_FIELDS = 25;

    public function kind(): string
    {
        return FormDestination::KIND_DISCORD;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $url = $config['webhook_url'] ?? null;
        if (! is_string($url) || ! preg_match('#^https://discord\.com/api/webhooks/[^\s]+#', $url)) {
            return ConfigValidation::fail(['webhook_url' => ['Discord webhook URL required (discord.com/api/webhooks/...).']]);
        }
        return ConfigValidation::ok();
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $url = (string) ($config['webhook_url'] ?? '');
        if ($url === '') {
            return AttemptResult::failed('discord: no webhook_url', 'config_missing_url');
        }

        $payload = $submission->payload ?: [];
        $fields = [];
        foreach (array_slice($payload, 0, self::MAX_FIELDS, true) as $key => $value) {
            $val = is_array($value) ? json_encode($value) : (string) $value;
            $fields[] = [
                'name' => mb_substr((string) $key, 0, 256),
                'value' => mb_substr($val, 0, self::EMBED_VALUE_LIMIT) ?: ' ',
                'inline' => false,
            ];
        }
        $body = [
            'username' => 'Inkwell',
            'embeds' => [[
                'title' => 'New form submission',
                'description' => "Submission ID: `{$submission->id}`",
                'fields' => $fields,
                'timestamp' => $submission->created_at?->toIso8601String(),
            ]],
        ];

        try {
            $start = microtime(true);
            $response = Http::asJson()->timeout(10)->post($url, $body);
            $latency = (int) ((microtime(true) - $start) * 1000);
            $snippet = substr((string) $response->body(), 0, 1024);
            return $response->successful()
                ? AttemptResult::sent('discord embed posted', $response->status(), $snippet, $latency)
                : AttemptResult::failed("discord returned {$response->status()}", 'http_error', $response->status(), $snippet, $latency);
        } catch (\Throwable $e) {
            Log::warning('discord delivery error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('discord transport error: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        return $v->valid ? new HealthResult(HealthResult::HEALTHY) : new HealthResult(HealthResult::FAILED, 'config invalid');
    }
}
