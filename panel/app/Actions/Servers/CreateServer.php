<?php

namespace App\Actions\Servers;

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class CreateServer
{
    /**
     * Store a server and have its node create and start the container.
     *
     * @param  array<string, mixed>  $data  Validated by StoreServerRequest.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function __invoke(User $owner, array $data): Server
    {
        // The row is rolled back when the node refuses, so the form can simply be sent again.
        return DB::transaction(function () use ($owner, $data): Server {
            $server = Server::create([
                'node_id' => Node::query()->firstOrFail()->id,
                'owner_id' => $owner->id,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'hostname' => $data['slug'].'.'.config('panel.base_domain'),
                'server_type' => $data['server_type'],
                'mc_version' => $data['mc_version'],
                'java_version' => $data['java_version'],
                'memory_mb' => $data['memory_mb'],
                'settings' => UpdateServer::settings($data),
                'eula_accepted_at' => now(),
            ]);

            (new NodeClient($server->node))->apply($server, start: true);

            return $server;
        });
    }
}
