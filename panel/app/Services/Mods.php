<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Mods from Modrinth for one server. Nothing is recorded: the server's mods folder is the truth.
 */
class Mods
{
    private const string MODRINTH = 'https://api.modrinth.com/v2';

    // CurseForge's numbers for the loaders.
    private const array CURSEFORGE_LOADERS = [1 => 'forge', 4 => 'fabric', 5 => 'quilt', 6 => 'neoforge'];

    /**
     * The loader and Minecraft version a server's mods must be built for, or null when that
     * cannot be found out right now.
     *
     * @return array{loader: string, version: string}|null
     */
    public static function target(Server $server): ?array
    {
        if (in_array($server->server_type, Server::MOD_LOADERS, true)) {
            $version = $server->mc_version === 'LATEST' ? (MinecraftVersions::releases()[0] ?? null) : $server->mc_version;

            return $version === null ? null : ['loader' => strtolower($server->server_type), 'version' => $version];
        }

        $key = "mod-target:{$server->id}:".md5((string) json_encode($server->modpack));

        if (($target = Cache::get($key)) === null) {
            try {
                $target = self::packTarget($server);
            } catch (ConnectionException|RequestException) {
                return null;
            }

            if ($target !== null) {
                Cache::put($key, $target, now()->addDay());
            }
        }

        return $target;
    }

    /**
     * What a modpack runs on, read from the catalogue the pack came from.
     *
     * @return array{loader: string, version: string}|null
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private static function packTarget(Server $server): ?array
    {
        $project = (string) ($server->modpack['project'] ?? '');
        $version = (string) ($server->modpack['version'] ?? '');

        if ($server->server_type === 'AUTO_CURSEFORGE') {
            $file = Modpacks::http()
                ->withHeaders(['x-api-key' => (string) Setting::value('curseforge_key')])
                ->get('https://api.curseforge.com/v1/mods/search', ['gameId' => 432, 'classId' => 4471, 'slug' => $project])
                ->throw()
                ->json('data.0.latestFilesIndexes.0');

            return self::pair(self::CURSEFORGE_LOADERS[$file['modLoader'] ?? 0] ?? null, $file['gameVersion'] ?? null);
        }

        if ($server->server_type === 'FTBA') {
            $pack = "https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$project}";
            $version = $version !== '' ? $version : (string) (collect(Modpacks::http()->get($pack)->throw()->json('versions'))->last()['id'] ?? '');
            $targets = collect(Modpacks::http()->get("{$pack}/{$version}")->throw()->json('targets'));

            return self::pair($targets->firstWhere('type', 'modloader')['name'] ?? null, $targets->firstWhere('type', 'game')['version'] ?? null);
        }

        // A pasted link names the pack, and maybe a version, in its path.
        if (preg_match('#^https://modrinth\.com/modpack/([^/]+)(?:/version/([^/]+))?#', $project, $link)) {
            [$project, $version] = [$link[1], $link[2] ?? ''];
        }

        $found = Modpacks::http()->get(self::MODRINTH."/project/{$project}/version".($version !== '' ? "/{$version}" : ''))->throw()->json();
        $found = $version !== '' ? $found : ($found[0] ?? []);

        return self::pair($found['loaders'][0] ?? null, $found['game_versions'][0] ?? null);
    }

    /**
     * @return array{loader: string, version: string}|null
     */
    private static function pair(mixed $loader, mixed $version): ?array
    {
        return is_string($loader) && is_string($version) ? ['loader' => strtolower($loader), 'version' => $version] : null;
    }
}
