<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a server to a new version of its modpack. A running server is backed up first and
 * is only touched once that backup is there.
 */
class UpdateModpack implements ShouldQueue
{
    use Queueable;

    public const string BACKUP = 'Before auto-update';

    // Tried again an hour later it would restart the server again, so a failure is final.
    public int $tries = 1;

    public int $timeout = 3600;

    public int $poll = 5;

    public function __construct(public int $serverId, public string $version, public string $name) {}

    /**
     * Install the version, or leave the server alone and record why.
     */
    public function handle(): void
    {
        $server = Server::query()->with('node')->find($this->serverId);

        if ($server === null || $server->modpack === null) {
            return;
        }

        $client = new NodeClient($server->node);

        try {
            $running = ($client->servers()[$server->slug]['state'] ?? 'offline') === 'running';

            if ($running) {
                $client->createBackup($server, self::BACKUP);
                $client->await($server, $this->timeout - 60, $this->poll);
                $backup = $client->backups($server)[0] ?? null;

                // A backup that fails is a line in the list, not an error of the server.
                if ($backup === null || $backup['by'] !== self::BACKUP || $backup['status'] !== 'completed') {
                    throw new RuntimeException((string) __('The backup before the update was not made.'));
                }
            }

            if (filled($server->modpack['version'] ?? null)) {
                DB::transaction(function () use ($server, $client): void {
                    $server->update(['modpack' => [...$server->modpack, 'version' => $this->version, 'version_name' => $this->name]]);
                    $client->apply($server);
                });
            } elseif ($running) {
                // Without a chosen version the Minecraft image installs the newest one as it starts.
                $client->power($server, 'restart');
            }

            ActivityLog::record('modpack.update', $server, ['version' => $this->name]);
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            report($exception);
            ActivityLog::record('modpack.update.failed', $server, ['reason' => $exception->getMessage()]);
        }
    }
}
