<?php

namespace App\Services\Spam\Signals;

use App\Services\Spam\SignalResult;
use App\Services\Spam\SpamSignal;
use App\Services\Spam\SubmissionContext;
use Illuminate\Support\Facades\Cache;

/**
 * Look up the client IP in the StopForumSpam-backed Redis set.
 * `inkwell:refresh-stopforumspam` cron loads the daily CSV into a Redis SET
 * named `spam:ip-blocklist`. Membership check is O(1).
 *
 * Hit = hard-block (100 points). Miss = 0 points.
 *
 * Off-band: the signal returns null when no Redis blocklist has been loaded
 * yet (fresh install). Keeps tests stable without seeding the blocklist.
 */
final class IpReputationSignal implements SpamSignal
{
    private const CACHE_KEY_PREFIX = 'spam:ip-blocklist:';
    private const LOADED_FLAG = 'spam:ip-blocklist:loaded';

    public function evaluate(SubmissionContext $ctx): ?SignalResult
    {
        if (! Cache::has(self::LOADED_FLAG)) {
            return null;
        }
        $ip = $ctx->clientIp;
        if (! is_string($ip) || $ip === '') {
            return null;
        }
        $hit = Cache::has(self::CACHE_KEY_PREFIX.$ip);
        return new SignalResult(
            name: 'ip_reputation',
            points: $hit ? 100 : 0,
            metadata: ['source' => 'stopforumspam', 'matched' => $hit, 'ip' => $ip],
        );
    }
}
