<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Sheets destination — appends a row per submission to a configured sheet.
 *
 * Config shape:
 *   spreadsheet_id   string  required
 *   sheet_name       string  required (tab name)
 *   field_mapping    array   form_field => sheet_column header
 *   access_token     string  required (OAuth bearer token; refresh out of band)
 *
 * Phase 5 scope: code path + tests with mocked HTTP. Full OAuth dance
 * (redirect / callback / refresh) wires up at deploy time; the access_token
 * is provisioned manually via the buyer's Google Cloud console for v1.
 */
final class GoogleSheetsDestination implements Destination
{
    private const API_BASE = 'https://sheets.googleapis.com/v4';

    public function kind(): string
    {
        return FormDestination::KIND_GOOGLE_SHEETS;
    }

    public function validateConfig(array $config): ConfigValidation
    {
        $errors = [];
        foreach (['spreadsheet_id', 'sheet_name', 'access_token'] as $required) {
            if (empty($config[$required]) || ! is_string($config[$required])) {
                $errors[$required] = ["{$required} is required."];
            }
        }
        if (isset($config['field_mapping']) && ! is_array($config['field_mapping'])) {
            $errors['field_mapping'] = ['field_mapping must be an object keyed by form field name.'];
        }
        return $errors === [] ? ConfigValidation::ok() : ConfigValidation::fail($errors);
    }

    public function deliver(Submission $submission, FormDestination $destination): AttemptResult
    {
        $config = $destination->config ?: [];
        $token = $config['access_token'] ?? null;
        $spreadsheet = $config['spreadsheet_id'] ?? null;
        $tab = $config['sheet_name'] ?? null;
        $mapping = $config['field_mapping'] ?? [];
        if (! is_string($token) || ! is_string($spreadsheet) || ! is_string($tab)) {
            return AttemptResult::failed('sheets: config missing fields', 'config_invalid');
        }

        // Build the row: map form fields → ordered values in the order the buyer
        // configured.
        $payload = $submission->payload ?: [];
        $row = is_array($mapping) && $mapping !== []
            ? array_map(static fn ($field) => $payload[$field] ?? '', $mapping)
            : array_values($payload);

        // Always prepend submission timestamp + ID for traceability.
        array_unshift($row, $submission->created_at?->toIso8601String() ?? '', $submission->id);

        $url = sprintf(
            '%s/spreadsheets/%s/values/%s:append?valueInputOption=USER_ENTERED',
            self::API_BASE,
            rawurlencode($spreadsheet),
            rawurlencode("'{$tab}'!A1"),
        );

        try {
            $start = microtime(true);
            $response = Http::withToken($token)->timeout(15)->post($url, [
                'values' => [array_values($row)],
            ]);
            $latency = (int) ((microtime(true) - $start) * 1000);
            if ($response->status() === 401) {
                return AttemptResult::failed('sheets: 401 — access token expired or revoked', 'oauth_expired', 401, null, $latency);
            }
            if ($response->successful()) {
                return AttemptResult::sent("appended row to {$tab}", $response->status(), null, $latency);
            }
            return AttemptResult::failed("sheets returned {$response->status()}", 'http_error', $response->status(), substr($response->body(), 0, 1024), $latency);
        } catch (\Throwable $e) {
            Log::warning('sheets delivery error', ['destination_id' => $destination->id, 'error' => $e->getMessage()]);
            return AttemptResult::failed('sheets transport error: '.$e->getMessage(), 'transport_error');
        }
    }

    public function healthCheck(FormDestination $destination): HealthResult
    {
        $v = $this->validateConfig($destination->config ?: []);
        return $v->valid ? new HealthResult(HealthResult::HEALTHY) : new HealthResult(HealthResult::FAILED, 'config invalid');
    }
}
