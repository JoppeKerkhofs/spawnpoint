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
    public function index(Request $request, Server $server): Response
    {
        $client = new NodeClient($server->node);

        try {
            $backups = $client->backups($server);
            $imports = $client->imports();
        } catch (ConnectionException|RequestException) {
            $error = __('The backups could not be read. Check that the Rust service is running.');
        }

        return Inertia::render('servers/Backups', [
            'server' => $server->forUser($request->user()),
            'autoBackupAt' => $server->schedules()->where(['action' => 'backup', 'is_active' => true])->whereNull('weekday')->value('time'),
            'states' => Inertia::defer(fn () => NodeClient::recentStates()),
            'backups' => $backups ?? [],
            'imports' => $imports ?? [],
            // A closure, so an upload can ask for a fresh token first.
            'upload' => fn () => ['url' => $client->fileUrl($server, 'upload')],
            'schedules' => $server->schedules()->orderBy('time')->get(['id', 'action', 'time', 'weekday', 'command', 'is_active']),
            'error' => $error ?? null,
            'timezone' => config('app.timezone'),
        ]);
    }

    /**
     * Start a backup now.
     */
    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->attempt(function () use ($request, $server): void {
            (new NodeClient($server->node))->createBackup($server, $request->user()->name);
            ActivityLog::record('backup.create', $server);
        }, __('Backup started.'));

        return back();
    }

    /**
     * Stop the backup that is running.
     */
    public function cancel(Server $server): RedirectResponse
    {
        $this->attempt(function () use ($server): void {
            (new NodeClient($server->node))->cancelBackup($server);
            ActivityLog::record('backup.cancel', $server);
        }, __('Backup cancelled.'), [404 => __('No backup is running any more.')]);

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
     * Save the automatic daily backup and how long backups are kept.
     */
    public function retention(Request $request, Server $server): RedirectResponse
    {
        $data = $request->validate([
            'backup_keep' => ['required', 'integer', 'between:1,365'],
            'backup_retention_days' => ['nullable', 'integer', 'between:1,3650'],
            'auto_backup' => ['nullable', 'boolean'],
            'auto_backup_at' => ['nullable', 'required_if_accepted:auto_backup', 'date_format:H:i'],
        ]);

        $server->update(['backup_keep' => $data['backup_keep'], 'backup_retention_days' => $data['backup_retention_days'] ?? null]);

        // The automatic backup is an ordinary daily schedule, so it also shows in the list of schedules.
        $daily = $server->schedules()->where('action', 'backup')->whereNull('weekday');
        if ($data['auto_backup'] ?? false) {
            $existing = $daily->first();
            $existing === null
                ? $server->schedules()->create(['action' => 'backup', 'time' => $data['auto_backup_at']])
                : $existing->update(['time' => $data['auto_backup_at'], 'is_active' => true]);
        } else {
            $daily->update(['is_active' => false]);
        }

        ActivityLog::record('backup.retention', $server, [...$server->only('backup_keep', 'backup_retention_days'), 'auto_backup' => (bool) ($data['auto_backup'] ?? false)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    /**
     * Replace the world with an uploaded zip or a world from the host's import folder.
     */
    public function import(FileRequest $request, Server $server): RedirectResponse
    {
        $input = $request->validate(['source' => ['required', Rule::in(['zip', 'host'])], 'start' => ['nullable', 'boolean']]);
        $source = $input['source'];

        $this->attempt(
            function () use ($server, $source, $input, $request): void {
                (new NodeClient($server->node))->importWorld($server, $source, $request->path(), (bool) ($input['start'] ?? false));
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
