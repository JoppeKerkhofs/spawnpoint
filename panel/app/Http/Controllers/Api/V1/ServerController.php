<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\DeleteServer;
use App\Actions\Servers\PowerServer;
use App\Actions\Servers\SetServerIcon;
use App\Actions\Servers\UpdateServer;
use App\Http\Controllers\Controller;
use App\Http\Requests\FileRequest;
use App\Http\Requests\LogRequest;
use App\Http\Requests\ServerIconRequest;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Jobs\MoveServer;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Services\CloudflareDns;
use App\Services\MemoryLimit;
use App\Services\MinecraftVersions;
use App\Services\Mods;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    /**
     * List the servers the token's owner may see, with their live state.
     */
    public function index(Request $request): JsonResponse
    {
        $states = NodeClient::states();

        return response()->json([
            'service_online' => $states['online'],
            'data' => Server::query()->visibleTo($request->user())->orderBy('name')->get()->map(fn (Server $server): array => self::describe($server, $states)),
        ]);
    }

    /**
     * Show one server with its live state, player count and resource use.
     */
    public function show(Server $server): JsonResponse
    {
        return response()->json(['data' => self::describe($server, NodeClient::states())]);
    }

    /**
     * Get the WebSocket address of the live console, valid for one minute.
     * Commands are only accepted when the token's owner may send them on this server.
     */
    public function console(Request $request, Server $server): JsonResponse
    {
        $write = $request->user()->can('act', [$server, 'commands']);

        return response()->json(['url' => (new NodeClient($server->node))->consoleUrl($server, $write), 'write' => $write]);
    }

    /**
     * List the log files, or with ?file= return one of them, optionally only the lines containing ?q=.
     */
    public function logs(LogRequest $request, Server $server): JsonResponse
    {
        $client = new NodeClient($server->node);
        $file = $request->validated('file');

        return response()->json($file === null
            ? ['files' => $client->logs($server)]
            : ['file' => $file, 'content' => $client->log($server, $file, (string) $request->validated('q'))]);
    }

    /**
     * List a folder, or with ?file=1 return a text file. Both take ?path=.
     */
    public function files(FileRequest $request, Server $server): JsonResponse
    {
        $client = new NodeClient($server->node);

        return response()->json($request->boolean('file')
            ? ['path' => $request->path(), 'content' => $client->readFile($server, $request->path())]
            : ['path' => $request->path(), 'entries' => $client->files($server, $request->path())]);
    }

    /**
     * Write a text file of up to 1 MB.
     */
    public function writeFile(FileRequest $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->writeFile($server, $request->path(), (string) $request->validated('content'));
        ActivityLog::record('file.save', $server, ['path' => $request->path()]);

        return response()->json(['message' => 'Saved.']);
    }

    /**
     * Delete a file, or a folder with everything in it.
     */
    public function deleteFile(FileRequest $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->deleteFile($server, $request->path());
        ActivityLog::record('file.delete', $server, ['path' => $request->path()]);

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Get addresses for sending a file of any size to a folder, or fetching one; valid for five minutes.
     * Upload with PUT to the upload address plus "&path=<folder>&name=<file name>"; download with
     * GET from the download address plus "&path=<file>".
     */
    public function transfer(Request $request, Server $server): JsonResponse
    {
        $client = new NodeClient($server->node);

        // The download address was always part of this answer; the upload one needs files:write.
        return response()->json(array_filter([
            'upload' => $request->user()->tokenCan('files:write') ? $client->fileUrl($server, 'upload') : null,
            'download' => $client->fileUrl($server, 'download'),
        ]));
    }

    /**
     * @param  array{online: bool, servers: array<string, array<string, mixed>>}  $states
     * @return array<string, mixed>
     */
    private static function describe(Server $server, array $states): array
    {
        $live = $states['servers'][$server->slug] ?? [];
        $runs = $server->hasMods() ? Mods::runs($server) : null;

        return [
            'slug' => $server->slug,
            'name' => $server->name,
            'hostname' => $server->hostname,
            'server_type' => $server->server_type,
            'mc_version' => $server->mc_version,
            // The Minecraft version itself, where mc_version says LATEST or the pack decides it.
            'game_version' => $runs['game'] ?? ($server->mc_version === 'LATEST' ? (MinecraftVersions::releases()[0] ?? null) : $server->mc_version),
            // What is installed, not what was asked for; the version is null until the pack is.
            'modpack' => $server->modpack === null ? null : [
                'catalogue' => ['MODRINTH' => 'modrinth', 'AUTO_CURSEFORGE' => 'curseforge', 'FTBA' => 'ftb'][$server->server_type] ?? null,
                'project' => $server->modpack['project'],
                'version' => $runs['version'] ?? null,
                'version_name' => $runs['version_name'] ?? null,
            ],
            'loader' => ($runs['loader'] ?? null) === null ? null : ['name' => $runs['loader'], 'version' => $runs['loader_version']],
            'picture' => $server->picture(),
            // Java flags for the players' game, not the server's own (jvm_opts).
            'client_jvm_opts' => ($server->settings['client_jvm_opts'] ?? '') ?: null,
            'state' => $live['state'] ?? ($states['online'] ? 'offline' : 'unknown'),
            'detail' => $live['detail'] ?? null,
            'players' => $live['players'] ?? null,
            'usage' => $live['usage'] ?? null,
            'disk_bytes' => $live['disk_bytes'] ?? null,
            'last_backup' => $live['last_backup'] ?? null,
        ];
    }

    /**
     * Start, stop, restart or kill a server through the same action the panel uses.
     */
    public function power(Request $request, Server $server, string $action, PowerServer $powerServer): JsonResponse
    {
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

    /**
     * Create a server, the same way the web form does.
     */
    public function store(StoreServerRequest $request, CreateServer $createServer): JsonResponse
    {
        $server = $createServer($request->user(), $request->validated());

        return response()->json([
            'data' => self::describe($server, NodeClient::states()),
            // Null unless the server was made but its DNS record was refused.
            'dns_error' => $createServer->dnsError,
        ], 201);
    }

    /**
     * Change a server's settings and recreate its container.
     */
    public function update(UpdateServerRequest $request, Server $server, UpdateServer $updateServer): JsonResponse
    {
        $updateServer($server, [
            ...$request->validated(),
            'is_default' => $request->user()->isAdmin() ? $request->boolean('is_default') : $server->is_default,
        ]);

        return response()->json(['data' => self::describe($server->refresh(), NodeClient::states())]);
    }

    /**
     * Set the server's picture: a PNG of 64 by 64 as a data URL.
     */
    public function icon(ServerIconRequest $request, Server $server, SetServerIcon $setIcon): JsonResponse
    {
        $setIcon($server, $request->png());

        return response()->json(['message' => __('Picture saved. Players see it after the next restart.'), 'picture' => $server->picture()]);
    }

    /**
     * Take the server's picture away; a modpack server shows its pack's picture again.
     */
    public function removeIcon(Server $server, SetServerIcon $setIcon): JsonResponse
    {
        $setIcon($server, null);

        return response()->json(['message' => __('Picture removed. Players see that after the next restart.'), 'picture' => $server->picture()]);
    }

    /**
     * Delete a server and its container. The data folder stays on the host.
     */
    public function destroy(Server $server, DeleteServer $deleteServer): Response
    {
        $deleteServer($server);

        return response()->noContent();
    }

    /**
     * Hand a server over to another node; the work itself is queued.
     */
    public function move(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'integer', Rule::exists('nodes', 'id')->whereNot('id', $server->node_id)],
        ]);

        $target = Node::query()->whereKey($data['node_id'])->firstOrFail();
        $free = MemoryLimit::freeMb($target);

        if ($free !== null && $free < $server->memory_mb) {
            return response()->json(['message' => __('Only :free MB is free on :node, and this server needs :needed MB.', [
                'free' => $free, 'node' => $target->name, 'needed' => $server->memory_mb,
            ])], 422);
        }

        MoveServer::dispatch($server->id, $target->id, $request->user()->id);

        return response()->json(['message' => __('The move has started.')], 202);
    }

    /**
     * Add the server's DNS record in Cloudflare again.
     */
    public function dns(Server $server): JsonResponse
    {
        abort_unless(CloudflareDns::enabled(), 404);

        // Caught here: left alone, the handler in bootstrap/app.php would blame the Rust service.
        try {
            CloudflareDns::create($server->hostname, $server->node->dns_target);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            ActivityLog::record('dns.failed', $server, ['hostname' => $server->hostname]);

            return response()->json(['message' => __('Cloudflare did not accept the DNS record. Check the token and its zone access in Settings.')], 502);
        }

        ActivityLog::record('dns.create', $server, ['hostname' => $server->hostname]);

        return response()->json(['message' => __('The DNS record for :hostname is in place.', ['hostname' => $server->hostname])]);
    }

    /**
     * Stop every server that is running or starting.
     */
    public function stopAll(PowerServer $powerServer): JsonResponse
    {
        $up = array_keys(array_filter(
            NodeClient::states()['servers'],
            fn (array $live): bool => in_array($live['state'], ['running', 'starting'], true),
        ));

        $servers = Server::query()->whereIn('slug', $up)->get();
        $failed = [];
        foreach ($servers as $server) {
            // One busy or unreachable server must not leave the rest running.
            try {
                $powerServer($server, 'stop');
            } catch (ConnectionException|RequestException) {
                $failed[] = $server->slug;
            }
        }

        return response()->json([
            'message' => trans_choice('{0} No server was running.|{1} Stopping one server.|[2,*] Stopping :count servers.', $servers->count() - count($failed)),
            'failed' => $failed,
        ], 202);
    }
}
