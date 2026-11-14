<?php

namespace App\Actions\Servers;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class PowerServer
{
    public const array ACTIONS = ['start', 'stop', 'restart', 'kill'];

    /**
     * Start, stop, restart or kill a server's container.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function __invoke(Server $server, string $action): void
    {
        (new NodeClient($server->node))->power($server, $action);
        ActivityLog::record("server.{$action}", $server);
    }
}
