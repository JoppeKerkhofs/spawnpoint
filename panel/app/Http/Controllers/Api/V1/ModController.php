<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ModController as WebModController;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\Mods;
use App\Services\NodeClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class ModController extends Controller implements HasMiddleware
{
    /**
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return WebModController::middleware();
    }

    /**
     * The mods in the server's folder, and the loader and version new ones must fit.
     */
    public function index(Server $server): JsonResponse
    {
        $target = Mods::target($server);

        return response()->json(['data' => Mods::installed($server, $target), 'target' => $target]);
    }

    /**
     * Search Modrinth for mods that fit this server.
     */
    public function search(Request $request, Server $server): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'offset' => ['nullable', 'integer', 'min:0', 'max:10000']]);
        $found = Mods::search(self::target($server), (string) ($input['q'] ?? ''), (int) ($input['offset'] ?? 0));

        return response()->json(['data' => $found['mods'], 'more' => $found['more']]);
    }

    /**
     * Install a mod, with the dependencies it requires where that is safe.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        $project = $request->validate(['project' => ['required', 'string', WebModController::PROJECT]])['project'];
        $result = Mods::install($server, self::target($server), $project);
        ActivityLog::record('mod.install', $server, ['mods' => $result['installed']]);

        return response()->json(['message' => __('Installed. Mods load on the next restart.'), ...$result], 201);
    }

    /**
     * Replace an installed mod with its newest build.
     */
    public function update(Server $server, string $file): JsonResponse
    {
        $target = self::target($server);
        $project = collect(Mods::installed($server, $target))->firstWhere('file', $file)['project']
            ?? abort(422, __('Modrinth does not know this file, so it cannot be updated here.'));
        $result = Mods::install($server, $target, $project);
        ActivityLog::record('mod.update', $server, ['mods' => $result['installed']]);

        return response()->json(['message' => __('Updated. Mods load on the next restart.'), ...$result]);
    }

    /**
     * Remove a mod by deleting its jar.
     */
    public function destroy(Server $server, string $file): JsonResponse
    {
        (new NodeClient($server->node))->deleteFile($server, "mods/{$file}");
        ActivityLog::record('mod.remove', $server, ['file' => $file]);

        return response()->json(['message' => __('Removed. It stays loaded until the next restart.')]);
    }

    /**
     * @return array{loader: string, version: string}
     */
    private static function target(Server $server): array
    {
        return Mods::target($server) ?? abort(422, __('Could not find out which loader and version this server runs.'));
    }
}
