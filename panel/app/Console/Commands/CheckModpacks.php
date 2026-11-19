<?php

namespace App\Console\Commands;

use App\Jobs\UpdateModpack;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\Modpacks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('modpacks:check')]
#[Description('Look for a new version of the modpack of every server that follows its updates')]
class CheckModpacks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        foreach (Server::query()->where('pack_check', true)->whereIn('server_type', Server::PACK_TYPES)->get() as $server) {
            try {
                $newest = Modpacks::newest($server->server_type, (string) ($server->modpack['project'] ?? ''));
            } catch (ConnectionException|RequestException $exception) {
                report($exception);

                continue;
            }

            if ($newest === null) {
                continue;
            }

            $chosen = $server->modpack['version'] ?? null;
            // A server without a chosen version is not asked what it runs: the first look only remembers.
            $known = $server->pack_latest ?? $chosen;
            $server->update(['pack_latest' => $newest['id']]);

            if ($known === null || $known === $newest['id'] || $chosen === $newest['id']) {
                continue;
            }

            if ($server->pack_auto_update) {
                UpdateModpack::dispatch($server->id, $newest['id'], $newest['name']);
            } else {
                ActivityLog::record('modpack.outdated', $server, ['version' => $newest['name']]);
            }

            $this->info("{$server->slug}: {$newest['name']}.");
        }

        return self::SUCCESS;
    }
}
