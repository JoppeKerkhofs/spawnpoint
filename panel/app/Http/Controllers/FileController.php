<?php

namespace App\Http\Controllers;

use App\Http\Requests\FileRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FileController extends Controller
{
    /**
     * Browse a folder of the server's data folder, optionally with one text file open in the editor.
     */
    public function index(FileRequest $request, Server $server): Response
    {
        $client = new NodeClient($server->node);
        $path = $request->path();

        try {
            $entries = $client->files($server, $path);

            if ($request->filled('edit')) {
                $file = ['path' => $request->path('edit'), 'content' => $client->readFile($server, $request->path('edit'))];
            }
        } catch (ConnectionException) {
            $error = __('The Rust service did not answer.');
        } catch (RequestException $exception) {
            $error = self::messages()[$exception->response->status()] ?? __('The Rust service refused the request.');
        }

        return Inertia::render('servers/Files', [
            'server' => $server->forUser($request->user()),
            'states' => Inertia::defer(fn () => NodeClient::recentStates($request->user())),
            'path' => $path,
            'entries' => $entries ?? [],
            'clientFiles' => $server->client_files ?? [],
            'clientRoots' => Server::CLIENT_ROOTS,
            'file' => $file ?? null,
            'error' => $error ?? null,
            // A closure, so an upload can ask for a fresh token first.
            'upload' => fn () => ['url' => $client->fileUrl($server, 'upload')],
        ]);
    }

    /**
     * Choose a file or folder for the players' launchers, or take it back.
     */
    public function client(FileRequest $request, Server $server): RedirectResponse
    {
        $path = $request->path();
        abort_unless(str_contains($path, '/') && in_array(strtok($path, '/'), Server::CLIENT_ROOTS, true), 422);

        $chosen = array_values(array_diff($server->client_files ?? [], [$path]));
        $on = $request->boolean('on');
        abort_if($on && count($chosen) >= 200, 422);
        $server->update(['client_files' => $on ? [...$chosen, $path] : $chosen]);
        ActivityLog::record('file.client', $server, ['path' => $path, 'on' => $on]);

        return back();
    }

    /**
     * Save a text file from the editor, or create an empty one.
     */
    public function save(FileRequest $request, Server $server): RedirectResponse
    {
        $this->attempt(
            function () use ($server, $request): void {
                (new NodeClient($server->node))->writeFile($server, $request->path(), (string) $request->validated('content'));
                ActivityLog::record('file.save', $server, ['path' => $request->path()]);
            },
            __('Saved. Most files are read again on the next restart.'),
            self::messages(),
        );

        return back();
    }

    /**
     * Create a folder.
     */
    public function folder(FileRequest $request, Server $server): RedirectResponse
    {
        $this->attempt(function () use ($server, $request): void {
            (new NodeClient($server->node))->makeFolder($server, $request->path());
            ActivityLog::record('file.folder', $server, ['path' => $request->path()]);
        }, messages: self::messages());

        return back();
    }

    /**
     * Rename or move a file or folder.
     */
    public function rename(FileRequest $request, Server $server): RedirectResponse
    {
        $this->attempt(
            function () use ($server, $request): void {
                (new NodeClient($server->node))->renameFile($server, $request->path('from'), $request->path('to'));
                ActivityLog::record('file.rename', $server, ['from' => $request->path('from'), 'to' => $request->path('to')]);
            },
            messages: self::messages(),
        );

        return back();
    }

    /**
     * Delete a file, or a folder with everything in it.
     */
    public function destroy(FileRequest $request, Server $server): RedirectResponse
    {
        $this->attempt(function () use ($server, $request): void {
            (new NodeClient($server->node))->deleteFile($server, $request->path());
            ActivityLog::record('file.delete', $server, ['path' => $request->path()]);
        }, messages: self::messages());

        return back();
    }

    /**
     * Send the browser to the Rust service to download a file, with a fresh token.
     */
    public function download(FileRequest $request, Server $server): RedirectResponse
    {
        return redirect()->away(
            (new NodeClient($server->node))->fileUrl($server, 'download').'&'.ltrim(NodeClient::query(['path' => $request->path()]), '?'),
        );
    }

    /**
     * What the Rust service's refusals mean for files.
     *
     * @return array<int, string>
     */
    private static function messages(): array
    {
        return [
            400 => __('That name cannot be used.'),
            403 => __('That path leads outside the server folder.'),
            404 => __('That file or folder no longer exists.'),
            409 => __('Something with that name already exists, or the folder is missing.'),
            413 => __('That file is too large to edit here. Download it instead.'),
            415 => __('That is not a text file. Download it instead.'),
        ];
    }
}
