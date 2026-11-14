<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Webhook;
use App\Services\NodeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('servers:watch')]
#[Description('Compare every server with a minute ago and tell webhooks about crashes and going online or offline')]
class WatchServers extends Command
{
    // States a server passes through on its way up; from any of these, "running" is news.
    private const array NOT_UP = ['offline', 'error', 'starting', 'installing', 'restarting', 'restoring', 'importing'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $states = NodeClient::states();

        // With the service down nothing is known, which is not the same as every server being offline.
        if (! $states['online']) {
            return self::SUCCESS;
        }

        $now = [];
        foreach (Server::all() as $server) {
            $now[$server->slug] = $states['servers'][$server->slug]['state'] ?? 'offline';
        }

        // ponytail: checked once a minute, so a crash and recovery inside one minute goes unnoticed.
        $before = Cache::get('watched-server-states');
        Cache::forever('watched-server-states', $now);

        foreach ($before === null ? [] : Server::all() as $server) {
            $was = $before[$server->slug] ?? null;
            $is = $now[$server->slug];

            $event = match (true) {
                $was === null || $was === $is => null,
                $is === 'error' => 'server.crashed',
                $is === 'running' && in_array($was, self::NOT_UP, true) => 'server.online',
                $is === 'offline' && $was === 'running' => 'server.offline',
                default => null,
            };

            if ($event !== null) {
                Webhook::notify($event, $server, 'System', $states['servers'][$server->slug]['detail'] ?? null);
            }
        }

        return self::SUCCESS;
    }
}
