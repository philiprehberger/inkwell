<?php

namespace App\Services\Destinations\Security;

use App\Models\Workspace;

/**
 * Plan 5.3 — per-workspace egress allowlist, required for credentialed
 * destinations.
 *
 * An unauthenticated webhook leaks data the tenant already owns. A credentialed
 * request is different in kind: the credential may not be theirs to use, and
 * the target may trust our IP rather than theirs. So a destination that carries
 * a secret must name where it is allowed to go.
 *
 * Non-credentialed destinations are unaffected — every existing destination
 * keeps working exactly as before.
 */
final class EgressPolicy
{
    /** @return array<int, string> errors, empty when acceptable */
    public function validate(?Workspace $workspace, string $url, array $headers): array
    {
        if (! (new HeaderPolicy)->containsSecret($headers)) {
            return [];
        }

        $allowlist = $this->allowlistFor($workspace);

        if ($allowlist === []) {
            return ['This destination carries a credential, so its workspace must define an egress allowlist first.'];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return ['Destination URL has no resolvable host.'];
        }

        foreach ($allowlist as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return [];
            }
        }

        return ["Host [{$host}] is not in this workspace's egress allowlist."];
    }

    /** @return array<int, string> */
    public function allowlistFor(?Workspace $workspace): array
    {
        $raw = $workspace?->egress_allowlist;

        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }

        return is_array($raw) ? array_values($raw) : [];
    }
}
