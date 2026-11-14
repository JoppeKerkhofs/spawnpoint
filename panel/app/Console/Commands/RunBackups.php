<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('backups:run')]
#[Description('Start the daily backup of every server whose backup time is this minute')]
class RunBackups extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // ponytail: matched on the exact minute, so a minute the scheduler misses skips that day's backup.
        foreach (Server::query()->where('backup_at', now()->format('H:i'))->with('node')->get() as $server) {
            try {
                (new NodeClient($server->node))->createBackup($server);
                ActivityLog::record('backup.create', $server, ['scheduled' => true]);
                $this->info("Backup started for {$server->slug}.");
            } catch (ConnectionException|RequestException $exception) {
                report($exception);
                $this->error("Backup failed to start for {$server->slug}.");
            }
        }

        return self::SUCCESS;
    }
}
