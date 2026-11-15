<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\DeleteServer;
use App\Actions\Servers\PowerServer;
use App\Actions\Servers\UpdateServer;
use App\Http\Requests\LogRequest;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use App\Services\CloudflareDns;
use App\Services\MemoryLimit;
use App\Services\MinecraftVersions;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return Inertia::render('servers/Create', [
            ...$this->options(),
            'imports' => rescue(fn () => (new NodeClient(Node::query()->firstOrFail()))->imports(), [], report: false),
        ]);
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

        if ($createServer->dnsError !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The server was created, but its DNS record could not be added. Check the Cloudflare settings.')]);
        }

        if ($request->boolean('world_zip')) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Server created. Choose your world .zip under "Import a world"; the server starts once it is in.')]);

            return to_route('servers.backups', $server);
        }

        return to_route('servers.show', $server);
    }

    /**
     * Show a server's live console.
     */
    public function show(Request $request, Server $server): Response
    {
        $user = $request->user();

        return Inertia::render('servers/Console', [
            'server' => $server->forUser($user),
            'states' => Inertia::defer(fn () => NodeClient::states()),
            // A closure, so a reconnect can ask for a fresh token on its own.
            // Without the commands permission the token streams output but refuses commands.
            'console' => fn () => ['url' => (new NodeClient($server->node))->consoleUrl($server, write: $user->can('act', [$server, 'commands']))],
            'bluemapUrl' => $server->bluemap_enabled ? rtrim(Setting::value('bluemap_url'), '/')."/{$server->slug}/" : null,
        ]);
    }

    /**
     * Show a server's configuration form.
     */
    public function configuration(Request $request, Server $server): Response
    {
        return Inertia::render('servers/Configuration', [
            ...$this->options($server),
            'server' => $server->forUser($request->user()),
            'states' => Inertia::defer(fn () => NodeClient::states()),
            'dnsEnabled' => CloudflareDns::enabled(),
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
            'server' => $server->forUser($request->user()),
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
            // Which server is the default affects other servers, so only an admin decides it.
            $updateServer($server, [
                ...$request->validated(),
                'is_default' => $request->user()->isAdmin() ? $request->boolean('is_default') : $server->is_default,
            ]);

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
     * Add the server's DNS record in Cloudflare again, after the first attempt failed.
     */
    public function dns(Server $server): RedirectResponse
    {
        abort_unless(CloudflareDns::enabled(), 404);

        try {
            CloudflareDns::create($server->hostname);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            ActivityLog::record('dns.failed', $server, ['hostname' => $server->hostname]);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Cloudflare did not accept the DNS record. Check the token and its zone access in Settings.')]);

            return back();
        }

        ActivityLog::record('dns.create', $server, ['hostname' => $server->hostname]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The DNS record for :hostname is in place.', ['hostname' => $server->hostname])]);

        return back();
    }

    /**
     * Stop every server that is running or starting.
     */
    public function stopAll(PowerServer $powerServer): RedirectResponse
    {
        $up = array_keys(array_filter(
            NodeClient::states()['servers'],
            fn (array $live): bool => in_array($live['state'], ['running', 'starting'], true),
        ));
        $servers = Server::query()->whereIn('slug', $up)->get();
        $stopped = 0;

        foreach ($servers as $server) {
            $stopped += (int) $this->attempt(function () use ($powerServer, $server): true {
                $powerServer($server, 'stop');

                return true;
            });
        }

        // A failure has already left its own message.
        if ($stopped === $servers->count()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No server was running.|{1} Stopping one server.|[2,*] Stopping :count servers.', $stopped)]);
        }

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
    private function options(?Server $server = null): array
    {
        return [
            'baseDomain' => Setting::value('base_domain'),
            // What this server may still be given: the limit minus what the other servers have.
            'memory' => ['limitMb' => MemoryLimit::mb(), 'freeMb' => MemoryLimit::freeMb($server)],
            'options' => [
                'types' => Server::TYPES,
                'mcVersions' => MinecraftVersions::releases(),
                'javaVersions' => Server::JAVA_VERSIONS,
                'curseforge' => filled(Setting::value('curseforge_key')),
                'difficulties' => Server::DIFFICULTIES,
                'modes' => Server::MODES,
            ],
        ];
    }
}
