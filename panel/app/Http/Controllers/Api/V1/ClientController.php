<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ModController;
use App\Http\Requests\FileRequest;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Modpacks;
use App\Services\Mods;
use App\Services\NodeClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a launcher needs to set a player's game up for a server.
 */
class ClientController extends Controller implements HasMiddleware
{
    /**
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return ModController::middleware();
    }

    /**
     * The mods a player's game must have, the pack's mods it must not, and the files chosen for it.
     */
    public function mods(Server $server): JsonResponse
    {
        $pack = $server->modpack === null ? null : Mods::runs($server)['files'];
        abort_if($server->modpack !== null && $pack === null, 409, (string) __('The modpack is not installed yet.'));

        return response()->json([...Mods::client($server, $pack), 'files' => (new NodeClient($server->node))->clientFiles($server)]);
    }

    /**
     * Where a CurseForge pack is downloaded. Its file list is inside that archive, so the
     * launcher reads it there and asks for the addresses with files().
     */
    public function pack(Server $server): JsonResponse
    {
        self::needsCurseForge($server);
        $pack = Modpacks::curseforgePack((string) $server->modpack['project'], Mods::runs($server)['version']);

        return $pack === null ? abort(404) : response()->json($pack);
    }

    /**
     * Where the files of a CurseForge pack are downloaded: the key that may ask stays here.
     */
    public function files(Request $request, Server $server): JsonResponse
    {
        self::needsCurseForge($server);
        $data = $request->validate(['files' => ['required', 'array', 'max:2000'], 'files.*' => ['integer', 'min:1']]);

        return response()->json(['files' => Modpacks::curseforgeFiles(array_map(intval(...), array_values($data['files'])))]);
    }

    private static function needsCurseForge(Server $server): void
    {
        abort_unless($server->server_type === 'AUTO_CURSEFORGE' && $server->modpack !== null, 404);
        abort_if(blank(Setting::value('curseforge_key')), 409, (string) __('CurseForge packs need CURSEFORGE_API_KEY in the panel environment.'));
    }

    /**
     * One jar from the mods folder. The panel passes it on itself: an address on the node
     * would open the whole server folder.
     */
    public function mod(Server $server, string $file): StreamedResponse
    {
        return self::pass($server, "mods/{$file}", 'application/java-archive');
    }

    /**
     * One of the files chosen for players, and nothing else in the server's folder.
     */
    public function file(Server $server, string $path): StreamedResponse
    {
        abort_unless($server->sendsToPlayers($path) && Validator::make(['path' => $path], ['path' => [FileRequest::PATH]])->passes(), 404);

        return self::pass($server, $path, 'application/octet-stream');
    }

    private static function pass(Server $server, string $path, string $type): StreamedResponse
    {
        $file = (new NodeClient($server->node))->download($server, $path, stream: true);
        $body = $file->toPsrResponse()->getBody();

        return response()->streamDownload(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(65536);
            }
        }, basename($path), ['Content-Type' => $type, 'Content-Length' => $file->header('Content-Length')]);
    }
}
