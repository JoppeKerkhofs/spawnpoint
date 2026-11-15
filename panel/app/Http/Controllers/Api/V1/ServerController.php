<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Controller;
use App\Http\Requests\FileRequest;
use App\Http\Requests\LogRequest;
use App\Models\ActivityLog;
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
     * List the backups.
     */
    public function backups(Server $server): JsonResponse
    {
        return response()->json(['data' => (new NodeClient($server->node))->backups($server)]);
    }

    /**
     * Start a backup.
     */
    public function backup(Request $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->createBackup($server, "{$request->user()->name} (API)");
        ActivityLog::record('backup.create', $server);

        return response()->json(['message' => "Accepted: backup of {$server->slug}."], 202);
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

        return [
            'slug' => $server->slug,
            'name' => $server->name,
            'hostname' => $server->hostname,
            'server_type' => $server->server_type,
            'mc_version' => $server->mc_version,
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
}
