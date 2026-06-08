<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Submission;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily PII-purge cron. Submissions older than the retention horizon
 * (default 90 days) have their payload + meta nulled in place; the
 * submission row itself stays for aggregate analytics, but the PII is gone.
 *
 * Scheduled via routes/console.php — daily at 03:15 UTC.
 */
#[Signature('inkwell:purge-old-submissions {--days=90 : retention horizon in days}')]
#[Description('Purge PII from submissions older than the retention horizon.')]
class PurgeOldSubmissionsCommand extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = now()->subDays($days);
        $count = 0;

        Submission::withoutGlobalScope(WorkspaceScope::class)
            ->where('created_at', '<', $threshold)
            ->whereNull('pii_purged_at')
            ->cursor()
            ->each(function (Submission $s) use (&$count) {
                $s->payload = [];
                // Redact IP to /24.
                $meta = $s->meta ?: [];
                if (isset($meta['client_ip']) && is_string($meta['client_ip'])) {
                    $meta['client_ip'] = preg_replace('/\.\d+$/', '.0', $meta['client_ip']);
                }
                $meta['pii_purged'] = true;
                $s->meta = $meta;
                $s->pii_purged_at = now();
                $s->save();
                $count++;
            });

        $this->info("Purged PII from {$count} submission(s) older than {$days} days.");
        return self::SUCCESS;
    }
}
