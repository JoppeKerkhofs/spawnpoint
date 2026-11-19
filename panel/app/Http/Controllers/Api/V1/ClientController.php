<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ModController;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Modpacks;
use App\Services\Mods;
use App\Services\NodeClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
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
     * The mods a player's game must have, and the pack's mods it must not.
     */
    public function mods(Server $server): JsonResponse
    {
        $pack = $server->modpack === null ? null : Mods::runs($server)['files'];
        abort_if($server->modpack !== null && $pack === null, 409, (string) __('The modpack is not installed yet.'));

        return response()->json(Mods::client($server, $pack));
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
        $jar = (new NodeClient($server->node))->download($server, "mods/{$file}", stream: true);
        $body = $jar->toPsrResponse()->getBody();

        return response()->streamDownload(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(65536);
            }
        }, $file, ['Content-Type' => 'application/java-archive', 'Content-Length' => $jar->header('Content-Length')]);
    }
}
