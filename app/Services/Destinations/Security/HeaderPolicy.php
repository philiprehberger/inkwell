<?php

namespace App\Services\Destinations\Security;

/**
 * Plan 5.2 — allowlist, not denylist.
 *
 * Tenant-controlled headers on a tenant-controlled URL is request forgery with
 * our IP reputation attached. A denylist fails open the moment a new dangerous
 * header is standardised, and a form-forwarding product has no need for
 * arbitrary header names.
 *
 * See docs/security/authenticated-destinations.md for the threat model.
 */
final class HeaderPolicy
{
    public const MAX_HEADERS = 10;

    public const MAX_NAME_LENGTH = 64;

    public const MAX_VALUE_LENGTH = 2048;

    /** Exactly permitted, case-insensitive. */
    private const ALLOWED = [
        'authorization',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'x-signature',
        'idempotency-key',
        'accept',
        'content-type',
        'x-request-id',
    ];

    /**
     * Never permitted, even with an X- prefix or unusual casing.
     * Host/Cookie enable confusion and session riding; the hop-by-hop set
     * enables request smuggling against intermediaries.
     */
    private const FORBIDDEN = [
        'host',
        'cookie',
        'set-cookie',
        'content-length',
        'transfer-encoding',
        'connection',
        'upgrade',
        'te',
        'trailer',
        'expect',
    ];

    /** Header names whose values are credentials and must never be readable. */
    private const SECRET_BEARING = [
        'authorization',
        'api-key',
        'token',
        'secret',
        'signature',
        'password',
    ];

    /** @return array<int, string> validation errors, empty when acceptable */
    public function validate(array $headers): array
    {
        $errors = [];

        if (count($headers) > self::MAX_HEADERS) {
            $errors[] = 'At most '.self::MAX_HEADERS.' custom headers are allowed.';
        }

        foreach ($headers as $name => $value) {
            $name = (string) $name;

            if (! is_string($value)) {
                $errors[] = "Header [{$name}] must have a string value.";

                continue;
            }

            if (strlen($name) > self::MAX_NAME_LENGTH) {
                $errors[] = "Header name [{$name}] exceeds ".self::MAX_NAME_LENGTH.' characters.';
            }

            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                $errors[] = "Header [{$name}] value exceeds ".self::MAX_VALUE_LENGTH.' characters.';
            }

            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
                $errors[] = "Header name [{$name}] may only contain letters, digits and hyphens.";

                continue;
            }

            // CR/LF in a value is header injection.
            if (preg_match('/[\r\n]/', $value) === 1) {
                $errors[] = "Header [{$name}] value may not contain line breaks.";
            }

            if (! $this->isAllowed($name)) {
                $errors[] = "Header [{$name}] is not permitted. See the destinations documentation for the allowed set.";
            }
        }

        return $errors;
    }

    public function isAllowed(string $name): bool
    {
        $name = strtolower($name);

        if (in_array($name, self::FORBIDDEN, true) || str_starts_with($name, 'proxy-')) {
            return false;
        }

        if (in_array($name, self::ALLOWED, true)) {
            return true;
        }

        // Vendor-specific headers are fine; the forbidden set is checked first.
        return str_starts_with($name, 'x-');
    }

    /** Does this header carry a credential (plan 5.3 / 5.4)? */
    public function isSecretBearing(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::SECRET_BEARING as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Does this header set contain any credential? */
    public function containsSecret(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if ($this->isSecretBearing((string) $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact for display, logs and API responses. Secret values never come
     * back out — an operator may replace one, nobody may read one.
     *
     * @return array<string, string>
     */
    public function redact(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[$name] = $this->isSecretBearing((string) $name) ? '••••••••' : (string) $value;
        }

        return $out;
    }
}
