<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Plan D-8 — scheme-usage evidence in the database, not inferred from logs.
 *
 * Plan 13.1 retires a legacy scheme only after 30 consecutive days of zero
 * traffic on it. Log retention on this host is 30 days with a 200 MB cap —
 * exactly the window the rule needs, which makes it far too thin to be the
 * evidence. A counter that starts the moment the scheme becomes selectable
 * cannot be truncated out from under the decision.
 *
 * Inkwell has no signed INBOUND surface — form submissions are unauthenticated
 * by design — so there is no inbound dual-accept to instrument here. What
 * matters for Inkwell's sunset decision is which scheme its OUTBOUND
 * deliveries actually used, per destination. That is what this records.
 */
class SignatureSchemeUsage extends Model
{
    protected $table = 'signature_scheme_usage';

    protected $fillable = ['destination_id', 'scheme', 'requests', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'requests' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Atomic upsert-and-increment; safe under concurrent queue workers. */
    public static function record(string $destinationId, string $scheme): void
    {
        $now = now();

        DB::table('signature_scheme_usage')->upsert(
            [[
                'destination_id' => $destinationId,
                'scheme' => $scheme,
                'requests' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['destination_id', 'scheme'],
            [
                'requests' => DB::raw('requests + 1'),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /** Has any destination used this scheme within the window (plan 13.1)? */
    public static function usedWithinDays(string $scheme, int $days): bool
    {
        return static::query()
            ->where('scheme', $scheme)
            ->where('last_seen_at', '>=', now()->subDays($days))
            ->exists();
    }
}
