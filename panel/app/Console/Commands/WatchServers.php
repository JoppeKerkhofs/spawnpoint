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
        // ponytail: checked once a minute, so a crash and recovery by hand inside one minute goes unnoticed.
        $before = Cache::get('watched-server-states');
        $restartsBefore = Cache::get('watched-server-restarts', []);

        $now = $restarts = [];
        foreach (Server::all() as $server) {
            $state = $states['servers'][$server->slug]['state'] ?? 'offline';
            // A node that does not answer says nothing about its servers, so what was last known stands.
            $now[$server->slug] = $state === 'unknown' ? ($before[$server->slug] ?? 'unknown') : $state;
            $restarts[$server->slug] = $state === 'unknown' ? ($restartsBefore[$server->slug] ?? 0) : ($states['servers'][$server->slug]['restarts'] ?? 0);
        }

        Cache::forever('watched-server-states', $now);
        Cache::forever('watched-server-restarts', $restarts);

        foreach ($before === null ? [] : Server::all() as $server) {
            $was = $before[$server->slug] ?? null;
            $is = $now[$server->slug];

            // Docker started it again after a crash, which the states a minute apart may not show.
            $restarted = $restarts[$server->slug] > ($restartsBefore[$server->slug] ?? 0);

            $event = match (true) {
                $restarted => 'server.crashed',
                $was === null || $was === $is => null,
                $is === 'error' => 'server.crashed',
                $is === 'running' && in_array($was, self::NOT_UP, true) => 'server.online',
                $is === 'offline' && $was === 'running' => 'server.offline',
                default => null,
            };

            if ($event !== null) {
                $detail = $restarted
                    ? (string) __('It was started again automatically (:count of 5).', ['count' => $restarts[$server->slug]], config('app.locale'))
                    : ($states['servers'][$server->slug]['detail'] ?? null);
                Webhook::notify($event, $server, 'System', $detail);
            }
        }

        return self::SUCCESS;
    }
}
