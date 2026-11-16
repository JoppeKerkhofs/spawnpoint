<?php

namespace App\Console\Commands;

use App\Events\Changed;
use App\Services\LiveListeners;
use App\Services\NodeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

#[Signature('servers:broadcast {--once : Look once instead of for a minute}')]
#[Description('Watch the nodes on behalf of every open page and announce the servers whose state changed')]
class BroadcastServers extends Command
{
    // Numbers that move all the time; on their own they are worth an update every fifth round.
    private const array RESTLESS = ['usage', 'players', 'disk_bytes'];

    /**
     * The scheduler starts this every minute; it looks every two seconds until the next start.
     */
    public function handle(LiveListeners $listeners): int
    {
        $until = now()->addSeconds(58);

        do {
            $this->look($listeners);
        } while (! $this->option('once') && now()->lt($until) && sleep(2) === 0);

        return self::SUCCESS;
    }

    private function look(LiveListeners $listeners): void
    {
        // Nobody has the panel open: the nodes are left alone.
        if (! $listeners->any()) {
            return;
        }

        $states = NodeClient::states();
        // Pages reloading because of an announcement read this instead of asking the nodes again.
        Cache::put('server-states', $states, 6);

        $thorough = Cache::increment('server-states-round') % 5 === 0;
        $before = Cache::get('server-states-seen', []);
        $seen = [];

        foreach ($states['servers'] as $slug => $state) {
            $steady = md5((string) json_encode(Arr::except($state, self::RESTLESS)));
            $all = md5((string) json_encode($state));
            [$steadyBefore, $allBefore] = $before[$slug] ?? [$steady, $all];

            if ($steady !== $steadyBefore || ($thorough && $all !== $allBefore)) {
                Changed::send($slug, 'state');
            }

            $seen[$slug] = [$steady, $thorough ? $all : $allBefore];
        }

        Cache::put('server-states-seen', $seen, 60);
    }
}
