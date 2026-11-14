<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\DeleteServer;
use App\Actions\Servers\PowerServer;
use App\Actions\Servers\UpdateServer;
use App\Http\Requests\LogRequest;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Models\Server;
use App\Services\MinecraftVersions;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerController extends Controller
{
    /**
     * Show the form for creating a server.
     */
    public function create(): Response
    {
        return Inertia::render('servers/Create', $this->options());
    }

    /**
     * Create a server and start it.
     */
    public function store(StoreServerRequest $request, CreateServer $createServer): RedirectResponse
    {
        $server = $this->attempt(fn () => $createServer($request->user(), $request->validated()));

        if (! $server instanceof Server) {
            return back()->withErrors(['node' => __('The server was not created.')]);
        }

        return to_route('servers.show', $server);
    }

    /**
     * Show a server's live console.
     */
    public function show(Server $server): Response
    {
        return Inertia::render('servers/Console', [
            'server' => $server,
            'states' => Inertia::defer(fn () => NodeClient::states()),
            // A closure, so a reconnect can ask for a fresh token on its own.
            'console' => fn () => ['url' => (new NodeClient($server->node))->consoleUrl($server, write: true)],
        ]);
    }

    /**
     * Show a server's configuration form.
     */
    public function configuration(Server $server): Response
    {
        return Inertia::render('servers/Configuration', [
            ...$this->options(),
            'server' => $server,
            'states' => Inertia::defer(fn () => NodeClient::states()),
        ]);
    }

    /**
     * Browse and search a server's log files.
     */
    public function logs(LogRequest $request, Server $server): Response
    {
        $client = new NodeClient($server->node);
        $search = (string) $request->validated('q');

        try {
            $files = $client->logs($server);
            $file = $request->validated('file') ?? ($files[0]['name'] ?? null);
            $content = $file === null ? '' : $client->log($server, $file, $search);
        } catch (ConnectionException|RequestException) {
            $error = __('The log could not be read. Check that the Rust service is running and the file still exists.');
        }

        return Inertia::render('servers/Logs', [
            'server' => $server,
            'states' => Inertia::defer(fn () => NodeClient::states()),
            'files' => $files ?? [],
            'file' => $file ?? null,
            'q' => $search,
            'content' => $content ?? '',
            'error' => $error ?? null,
        ]);
    }

    /**
     * Download a whole log file as plain text.
     */
    public function downloadLog(LogRequest $request, Server $server): StreamedResponse
    {
        $file = $request->validated('file') ?? abort(404);
        $content = rescue(fn () => (new NodeClient($server->node))->log($server, $file), fn () => abort(404), report: false);

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $server->slug.'-'.preg_replace('/\.gz$/', '', $file),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * Save a server's settings and recreate its container.
     */
    public function update(UpdateServerRequest $request, Server $server, UpdateServer $updateServer): RedirectResponse
    {
        $saved = $this->attempt(function () use ($updateServer, $server, $request): true {
            $updateServer($server, $request->validated());

            return true;
        }, __('Saved. The container is being recreated.'));

        return $saved ? back() : back()->withErrors(['node' => __('The changes were not saved.')]);
    }

    /**
     * Start, stop, restart or kill a server.
     */
    public function power(Server $server, string $action, PowerServer $powerServer): RedirectResponse
    {
        $this->attempt(fn () => $powerServer($server, $action));

        return back();
    }

    /**
     * Delete a server and its container.
     */
    public function destroy(Server $server, DeleteServer $deleteServer): RedirectResponse
    {
        $deleted = $this->attempt(function () use ($deleteServer, $server): true {
            $deleteServer($server);

            return true;
        }, __('Server deleted. Its data folder is still on the host.'));

        return $deleted ? to_route('dashboard') : back();
    }

    /**
     * The choices the server forms offer.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'baseDomain' => config('panel.base_domain'),
            'options' => [
                'types' => Server::TYPES,
                'mcVersions' => MinecraftVersions::releases(),
                'javaVersions' => Server::JAVA_VERSIONS,
                'curseforge' => filled(config('panel.curseforge_key')),
                'difficulties' => Server::DIFFICULTIES,
                'modes' => Server::MODES,
            ],
        ];
    }
}
