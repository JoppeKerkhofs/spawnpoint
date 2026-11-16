<?php

namespace App\Http\Controllers;

use App\Exceptions\ModrinthUnavailable;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\Mods;
use App\Services\NodeClient;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Inertia\Inertia;
use Inertia\Response;

class ModController extends Controller implements HasMiddleware
{
    // A slug or id only: it becomes part of an address on Modrinth's API.
    public const string PROJECT = 'regex:/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,63}$/';

    /**
     * Vanilla, Paper and Pumpkin servers have no mods, so for them none of this exists.
     *
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next) {
            $server = $request->route('server');

            return $server instanceof Server && $server->hasMods() ? $next($request) : abort(404);
        }];
    }

    /**
     * The mods in the server's folder, and what the catalogue has to be searched for.
     */
    public function index(Request $request, Server $server): Response
    {
        $target = Mods::target($server);

        try {
            $installed = Mods::installed($server, $target);
        } catch (ConnectionException) {
            $error = __('The Rust service did not answer.');
        } catch (RequestException) {
            $error = __('The Rust service refused the request.');
        }

        return Inertia::render('servers/Mods', [
            'server' => $server->forUser($request->user()),
            'states' => Inertia::defer(fn () => NodeClient::states()),
            'target' => $target,
            'installed' => $installed ?? [],
            'error' => $error ?? null,
            // Dependencies the last install named but did not add, on CurseForge and FTB packs.
            'missing' => session('missing', []),
        ]);
    }

    /**
     * Search Modrinth for mods that fit this server, for the list on the page.
     */
    public function search(Request $request, Server $server): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'offset' => ['nullable', 'integer', 'min:0', 'max:10000']]);
        $target = Mods::target($server);

        if ($target === null) {
            return response()->json(['message' => __('Could not find out which loader and version this server runs.')], 422);
        }

        try {
            return response()->json(Mods::search($target, (string) ($input['q'] ?? ''), (int) ($input['offset'] ?? 0)));
        } catch (ModrinthUnavailable) {
            return response()->json(['message' => __('The mod list could not be loaded.')], 502);
        }
    }

    /**
     * Install a mod from the catalogue.
     */
    public function store(Request $request, Server $server): RedirectResponse
    {
        $project = $request->validate(['project' => ['required', 'string', self::PROJECT]])['project'];

        return $this->install($server, 'mod.install', fn (): string => $project);
    }

    /**
     * Replace an installed mod with its newest build.
     */
    public function update(Server $server, string $file): RedirectResponse
    {
        return $this->install($server, 'mod.update', fn (array $target): ?string => collect(Mods::installed($server, $target))->firstWhere('file', $file)['project'] ?? null);
    }

    /**
     * Remove a mod by deleting its jar.
     */
    public function destroy(Server $server, string $file): RedirectResponse
    {
        $this->attempt(function () use ($server, $file): void {
            (new NodeClient($server->node))->deleteFile($server, "mods/{$file}");
            ActivityLog::record('mod.remove', $server, ['file' => $file]);
        }, __('Removed :file. It stays loaded until the next restart.', ['file' => $file]), [404 => __('That file or folder no longer exists.')]);

        return back();
    }

    /**
     * @param  Closure(array{loader: string, version: string}): ?string  $project  Which Modrinth project, or null when the file is not one.
     */
    private function install(Server $server, string $event, Closure $project): RedirectResponse
    {
        $target = Mods::target($server);

        if ($target === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not find out which loader and version this server runs.')]);

            return back();
        }

        $result = $this->attempt(function () use ($server, $target, $project): array|false {
            $id = $project($target);

            return $id === null ? false : Mods::install($server, $target, $id);
        });

        if ($result === false) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Modrinth does not know this file, so it cannot be updated here.')]);
        } elseif ($result !== null) {
            ActivityLog::record($event, $server, ['mods' => $result['installed']]);
            $names = ['names' => implode(', ', $result['installed'])];
            Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
                $event === 'mod.update' => __('Updated :names. It loads on the next restart.', $names),
                count($result['installed']) === 1 => __('Installed :names. It loads on the next restart.', $names),
                default => __('Installed :names. They load on the next restart.', $names),
            }]);

            return back()->with('missing', $result['missing']);
        }

        return back();
    }
}
