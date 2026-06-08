<?php

namespace App\Services;

/**
 * Refuses outbound HTTP to private / loopback / link-local IPs so a hostile
 * subscriber URL can't be used to relay traffic to internal services.
 *
 * Resolves the URL host once via gethostbyname (good enough for a portfolio
 * demo). For production you'd want DNS rebinding protection too — pin the
 * resolved IP and use it as the connect address.
 */
class SsrfGuard
{
    /**
     * @return string|null  Null if allowed; an error code string if blocked.
     */
    public function check(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return 'invalid_url';
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'invalid_scheme';
        }

        $host = $parsed['host'];

        // Allow plain hostnames; reject IPs in private ranges.
        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : @gethostbyname($host);

        if ($ip === $host && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            // gethostbyname returns the input on failure — DNS lookup failed.
            return 'dns_failed';
        }

        if (! $this->isPublicIp($ip)) {
            return 'private_ip_blocked';
        }

        return null;
    }

    private function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7, fd00::/8
        // FILTER_FLAG_NO_RES_RANGE blocks 127/8, 169.254/16, 224/4, 240/4, and reserved IPv6 ranges
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
