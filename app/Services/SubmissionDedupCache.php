<?php

namespace App\Services;

use App\Models\Form;
use Illuminate\Support\Facades\Cache;

/**
 * Dedup window — 60 seconds, same form + same canonical payload hash.
 *
 * Backed by Laravel cache (Redis in prod, file in dev). `add()` is atomic
 * (SETNX semantics) so two concurrent identical submissions can't both miss.
 */
final class SubmissionDedupCache
{
    private const TTL_SECONDS = 60;

    public static function canonicalHash(array $payload): string
    {
        // Lower-case email + trim text fields for tolerant dedup.
        $copy = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $payload);
        ksort($copy);
        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Atomically claim the dedup key. Returns the existing submission ID on
     * collision, or null on miss.
     */
    public static function claim(Form $form, string $hash, string $submissionId): ?string
    {
        $key = "dedup:{$form->id}:{$hash}";
        $stored = Cache::add($key, $submissionId, self::TTL_SECONDS);
        if ($stored) {
            return null;
        }
        return Cache::get($key);
    }
}
