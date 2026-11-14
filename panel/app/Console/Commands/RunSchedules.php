<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Schedule;
use App\Services\NodeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('schedules:run')]
#[Description('Run every active schedule whose time is this minute')]
class RunSchedules extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // ponytail: matched on the exact minute, so a minute the scheduler misses skips that run.
        $due = Schedule::query()
            ->where('is_active', true)
            ->where('time', now()->format('H:i'))
            ->where(fn ($query) => $query->whereNull('weekday')->orWhere('weekday', now()->dayOfWeek))
            ->with('server.node')
            ->get();

        foreach ($due as $schedule) {
            $server = $schedule->server;
            $client = new NodeClient($server->node);

            try {
                match ($schedule->action) {
                    'backup' => $client->createBackup($server),
                    'command' => $client->command($server, (string) $schedule->command),
                    default => $client->power($server, $schedule->action),
                };

                ActivityLog::record('schedule.run', $server, array_filter(['action' => $schedule->action, 'command' => $schedule->command]));
                $this->info("Ran {$schedule->action} for {$server->slug}.");
            } catch (ConnectionException|RequestException $exception) {
                report($exception);
                $this->error("Could not run {$schedule->action} for {$server->slug}.");
            }
        }

        return self::SUCCESS;
    }
}
