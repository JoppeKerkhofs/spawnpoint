<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\DeleteServer;
use App\Actions\Servers\PowerServer;
use App\Actions\Servers\UpdateServer;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Models\Server;
use App\Services\MinecraftVersions;
use App\Services\NodeClient;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
     * Show a server with its configuration form.
     */
    public function show(Server $server): Response
    {
        return Inertia::render('servers/Show', [
            ...$this->options(),
            'server' => $server,
            'states' => Inertia::defer(fn () => NodeClient::states()),
        ]);
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
     * Run an action against the node and turn its failures into a toast.
     */
    private function attempt(Closure $action, ?string $success = null): mixed
    {
        try {
            $result = $action();
        } catch (ConnectionException) {
            return $this->fail(__('The Rust service did not answer.'));
        } catch (RequestException $exception) {
            return $this->fail(match ($exception->response->status()) {
                404 => __('This server has no container yet. Save its configuration to create it.'),
                409 => __('The server is busy with another task.'),
                default => __('The Rust service refused the request (:status).', ['status' => $exception->response->status()]),
            });
        }

        if ($success !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $success]);
        }

        return $result;
    }

    private function fail(string $message): null
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return null;
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
                'difficulties' => Server::DIFFICULTIES,
                'modes' => Server::MODES,
            ],
        ];
    }
}
