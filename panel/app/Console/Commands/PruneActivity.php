<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('activity:prune')]
#[Description('Delete activity-log entries older than the retention set on the panel settings page')]
class PruneActivity extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) Setting::value('activity_retention_days');

        if ($days < 1) {
            $this->info('No retention is set, so nothing is deleted.');

            return self::SUCCESS;
        }

        // Chunked, so a log that was never pruned does not lock the table in one statement.
        $cutoff = now()->subDays($days);
        $deleted = 0;
        do {
            $batch = ActivityLog::query()->where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$deleted} entries older than {$days} days deleted.");

        return self::SUCCESS;
    }
}
