<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BackupController extends Controller
{
    /**
     * List the backups, newest first.
     */
    public function index(Server $server): JsonResponse
    {
        return response()->json(['data' => (new NodeClient($server->node))->backups($server)]);
    }

    /**
     * Start a backup. It runs in the background on the node.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        // "(API)" is what the node's backup list has always shown for a backup made by a token.
        (new NodeClient($server->node))->createBackup($server, "{$request->user()->name} (API)");
        ActivityLog::record('backup.create', $server);

        return response()->json(['message' => "Accepted: backup of {$server->slug}."], 202);
    }

    /**
     * Stop the backup that is running; what was written so far is thrown away.
     */
    public function cancel(Server $server): JsonResponse
    {
        (new NodeClient($server->node))->cancelBackup($server);
        ActivityLog::record('backup.cancel', $server);

        return response()->json(['message' => __('The backup was stopped.')], 202);
    }

    /**
     * How many backups to keep and for how long. The daily automatic backup of the web page is
     * an ordinary schedule; an app sets it through the schedules endpoints.
     */
    public function retention(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'backup_keep' => ['required', 'integer', 'between:1,365'],
            'backup_retention_days' => ['nullable', 'integer', 'between:1,3650'],
        ]);

        $server->update(['backup_keep' => $data['backup_keep'], 'backup_retention_days' => $data['backup_retention_days'] ?? null]);
        ActivityLog::record('backup.retention', $server, $server->only('backup_keep', 'backup_retention_days'));

        return response()->json(['data' => $server->only('backup_keep', 'backup_retention_days')]);
    }

    /**
     * The address the backup can be downloaded from, with a token valid for five minutes.
     */
    public function download(Server $server, string $backup): JsonResponse
    {
        return response()->json(['url' => (new NodeClient($server->node))->backupUrl($server, $backup)]);
    }

    /**
     * Put a backup back. The node archives the current state first.
     */
    public function restore(Server $server, string $backup): JsonResponse
    {
        (new NodeClient($server->node))->restoreBackup($server, $backup);
        ActivityLog::record('backup.restore', $server, ['backup' => $backup]);

        return response()->json(['message' => __('The restore has started.')], 202);
    }

    /**
     * Keep a backup through retention, or let it expire again.
     */
    public function lock(Request $request, Server $server, string $backup): JsonResponse
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];
        (new NodeClient($server->node))->lockBackup($server, $backup, $locked);

        return response()->json(['message' => $locked ? __('The backup is locked.') : __('The backup is unlocked.')]);
    }

    /**
     * Remove a backup for good.
     */
    public function destroy(Server $server, string $backup): JsonResponse
    {
        (new NodeClient($server->node))->deleteBackup($server, $backup);
        ActivityLog::record('backup.delete', $server, ['backup' => $backup]);

        return response()->json(['message' => __('The backup was deleted.')]);
    }

    /**
     * Replace the world with one from a zip in the server's folder or from the node's import folder.
     */
    public function import(FileRequest $request, Server $server): JsonResponse
    {
        // FileRequest already refused a path that leaves the folder.
        $data = $request->validate([
            'source' => ['required', Rule::in(['zip', 'host'])],
            'path' => ['required'],
            'start' => ['nullable', 'boolean'],
        ]);

        (new NodeClient($server->node))->importWorld($server, $data['source'], $request->path(), (bool) ($data['start'] ?? false));
        ActivityLog::record('world.import', $server, ['source' => $data['source'], 'path' => $request->path()]);

        return response()->json(['message' => __('The import has started.')], 202);
    }

    /**
     * The worlds in the node's import folder, for an import with source "host".
     */
    public function imports(Server $server): JsonResponse
    {
        return response()->json(['data' => (new NodeClient($server->node))->imports()]);
    }
}
