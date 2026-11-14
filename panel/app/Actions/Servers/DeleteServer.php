<?php

namespace App\Actions\Servers;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\CloudflareDns;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class DeleteServer
{
    /**
     * Remove a server and its container. The data folder stays on the host.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function __invoke(Server $server): void
    {
        (new NodeClient($server->node))->delete($server);
        ActivityLog::record('server.delete', $server);

        $server->delete();

        if (CloudflareDns::enabled()) {
            // Best effort: a record that stays behind is harmless and can be removed in Cloudflare.
            rescue(fn () => CloudflareDns::delete($server->hostname));
        }
    }
}
