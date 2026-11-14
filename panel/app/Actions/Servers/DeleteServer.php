<?php

namespace App\Actions\Servers;

use App\Models\Server;
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

        $server->delete();
    }
}
