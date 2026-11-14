<?php

namespace App\Http\Controllers;

use App\Http\Requests\FileRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    /**
     * Show a server's backups, its backup schedule and the worlds that can be imported.
     */
    public function index(Server $server): Response
    {
        $client = new NodeClient($server->node);

        try {
            $backups = $client->backups($server);
            $imports = $client->imports();
        } catch (ConnectionException|RequestException) {
            $error = __('The backups could not be read. Check that the Rust service is running.');
        }

        return Inertia::render('servers/Backups', [
            'server' => $server,
            'states' => Inertia::defer(fn () => NodeClient::states()),
            'backups' => $backups ?? [],
            'imports' => $imports ?? [],
            'error' => $error ?? null,
            'timezone' => config('app.timezone'),
        ]);
    }

    /**
     * Start a backup now.
     */
    public function store(Server $server): RedirectResponse
    {
        $this->attempt(function () use ($server): void {
            (new NodeClient($server->node))->createBackup($server);
            ActivityLog::record('backup.create', $server);
        }, __('Backup started.'));

        return back();
    }

    /**
     * Put a backup back in place of the server's current files.
     */
    public function restore(Server $server, string $backup): RedirectResponse
    {
        $this->attempt(
            function () use ($server, $backup): void {
                (new NodeClient($server->node))->restoreBackup($server, $backup);
                ActivityLog::record('backup.restore', $server, ['backup' => $backup]);
            },
            __('Restore started. The current state is backed up first.'),
            self::messages(),
        );

        return back();
    }

    /**
     * Lock a backup so retention keeps it, or unlock it.
     */
    public function lock(Request $request, Server $server, string $backup): RedirectResponse
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];

        $this->attempt(fn () => (new NodeClient($server->node))->lockBackup($server, $backup, (bool) $locked), messages: self::messages());

        return back();
    }

    /**
     * Delete a backup.
     */
    public function destroy(Server $server, string $backup): RedirectResponse
    {
        $this->attempt(function () use ($server, $backup): void {
            (new NodeClient($server->node))->deleteBackup($server, $backup);
            ActivityLog::record('backup.delete', $server, ['backup' => $backup]);
        }, messages: self::messages());

        return back();
    }

    /**
     * Send the browser to the Rust service to download a backup, with a fresh token.
     */
    public function download(Server $server, string $backup): RedirectResponse
    {
        return redirect()->away((new NodeClient($server->node))->backupUrl($server, $backup));
    }

    /**
     * Save the daily backup time and how many backups to keep.
     */
    public function schedule(Request $request, Server $server): RedirectResponse
    {
        $server->update($request->validate([
            'backup_at' => ['nullable', 'date_format:H:i'],
            'backup_keep' => ['required', 'integer', 'between:1,365'],
        ]));

        ActivityLog::record('backup.schedule', $server, $server->only('backup_at', 'backup_keep'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup schedule saved.')]);

        return back();
    }

    /**
     * Replace the world with an uploaded zip or a world from the host's import folder.
     */
    public function import(FileRequest $request, Server $server): RedirectResponse
    {
        $source = $request->validate(['source' => ['required', Rule::in(['zip', 'host'])]])['source'];

        $this->attempt(
            function () use ($server, $source, $request): void {
                (new NodeClient($server->node))->importWorld($server, $source, $request->path());
                ActivityLog::record('world.import', $server, ['source' => $source, 'path' => $request->path()]);
            },
            __('Import started. The current world is backed up first.'),
            [404 => __('That world was not found. A zip must be in this server\'s files and end in .zip.'), ...self::messages()],
        );

        return back();
    }

    /**
     * @return array<int, string>
     */
    private static function messages(): array
    {
        return [
            404 => __('That backup no longer exists.'),
            409 => __('The server is busy with another task.'),
        ];
    }
}
