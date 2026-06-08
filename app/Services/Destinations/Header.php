<?php

namespace App\Services\Destinations;

/**
 * Strip CRLF from any submitter-controlled value before it touches an outbound
 * mail header. Header injection via submitter-controlled fields is one of the
 * oldest webform abuse patterns — this filter is small and load-bearing.
 *
 * Also strips control characters in [0x00, 0x1F] that some MTAs interpret as
 * line-folding boundaries.
 */
final class Header
{
    public static function sanitize(?string $value, int $maxLength = 200): string
    {
        if ($value === null) {
            return '';
        }
        $stripped = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        $trimmed = trim($stripped);
        return mb_substr($trimmed, 0, $maxLength);
    }
}
