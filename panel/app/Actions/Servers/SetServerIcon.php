<?php

namespace App\Actions\Servers;

use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class SetServerIcon
{
    /**
     * Give the server a picture, or with null take it away. Minecraft reads server-icon.png when
     * it starts, so players see the change after the next restart.
     *
     * @param  string|null  $png  A 64 by 64 PNG, checked by ServerIconRequest.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function __invoke(Server $server, ?string $png): void
    {
        $client = new NodeClient($server->node);

        if ($png !== null) {
            $client->writeFile($server, 'server-icon.png', $png);
        } else {
            try {
                $client->deleteFile($server, 'server-icon.png');
            } catch (RequestException $exception) {
                // Already gone is what was asked for.
                throw_unless($exception->response->status() === 404, $exception);
            }
        }

        $server->update(['icon' => $png === null ? null : base64_encode($png)]);
        ActivityLog::record('server.icon', $server);
    }
}
