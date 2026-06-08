<?php

namespace App\Services;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a submission payload against the form's JSON Schema.
 * Returns field-level error map on failure.
 */
final class SchemaValidator
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $schema
     * @return array{ok: true} | array{ok: false, errors: array<string, array<string>>}
     */
    public static function validate(array $payload, array $schema): array
    {
        // Empty schema = no constraints; accept.
        if ($schema === [] || ($schema['type'] ?? null) === null && empty($schema['properties'] ?? null)) {
            return ['ok' => true];
        }

        $validator = new Validator;
        $result = $validator->validate(json_decode(json_encode($payload)), json_decode(json_encode($schema)));

        if ($result->isValid()) {
            return ['ok' => true];
        }

        $formatter = new ErrorFormatter;
        $flat = $formatter->format($result->error(), multiple: true);

        // opis returns shape like ["/property" => "message"] — normalise to
        // ["property" => ["message"]] for Laravel-style errors.
        $errors = [];
        foreach ($flat as $path => $messages) {
            $field = ltrim((string) $path, '/') ?: '_';
            $errors[$field] = is_array($messages) ? $messages : [(string) $messages];
        }

        return ['ok' => false, 'errors' => $errors];
    }
}
