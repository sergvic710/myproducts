<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SGroup\SGroupSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Queue new S-ryhmä receipts from S-Etukortti. Scheduled every 15 minutes in
 * routes/console.php; run it by hand to backfill an older range.
 */
class SGroupSyncCommand extends Command
{
    protected $signature = 'sgroup:sync
                            {--from= : Start date (YYYY-MM-DD) instead of the last saved receipt}
                            {--quiet-chat : Do not report each imported receipt in Telegram}';

    protected $description = 'Fetch new S-ryhmä receipts from S-Etukortti and queue them for import';

    public function handle(SGroupSync $sync): int
    {
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from')) : null;

        $queued = $sync->run($from, notify: ! $this->option('quiet-chat'));

        if ($queued === null) {
            $this->warn('No active S-Etukortti session. Send /sgroup <sessionId> <xsrf-token> to the bot.');

            // Not a failure: with no session the scheduled run simply has nothing to do.
            return self::SUCCESS;
        }

        $this->info("Queued {$queued} new receipt(s).");

        return self::SUCCESS;
    }
}
