<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * List the servers the token's owner may see, with their live state.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('servers:read'), 403, 'This token lacks the servers:read ability.');

        $states = NodeClient::states();

        return response()->json([
            'service_online' => $states['online'],
            'data' => Server::query()->visibleTo($request->user())->orderBy('name')->get()->map(fn (Server $server): array => [
                'slug' => $server->slug,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'server_type' => $server->server_type,
                'mc_version' => $server->mc_version,
                'state' => $states['servers'][$server->slug]['state'] ?? ($states['online'] ? 'offline' : 'unknown'),
                'detail' => $states['servers'][$server->slug]['detail'] ?? null,
            ]),
        ]);
    }

    /**
     * Start, stop, restart or kill a server through the same action the panel uses.
     */
    public function power(Request $request, Server $server, string $action, PowerServer $powerServer): JsonResponse
    {
        abort_unless($request->user()->tokenCan('servers:power'), 403, 'This token lacks the servers:power ability.');

        try {
            $powerServer($server, $action);
        } catch (ConnectionException) {
            return response()->json(['message' => 'The Rust service did not answer.'], 502);
        } catch (RequestException $exception) {
            return response()->json(
                ['message' => $exception->response->status() === 409 ? 'The server is busy with another task.' : 'The Rust service refused the request.'],
                $exception->response->status() === 409 ? 409 : 502,
            );
        }

        return response()->json(['message' => "Accepted: {$action} {$server->slug}."], 202);
    }
}
