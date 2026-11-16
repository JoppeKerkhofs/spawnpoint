<?php

namespace App\Services;

use App\Exceptions\ModrinthUnavailable;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

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
            } catch (ConnectionException|RequestException|ModrinthUnavailable) {
                return null;
            }

            if ($target !== null) {
                Cache::put($key, $target, now()->addDay());
            }
        }

        return $target;
    }

    /**
     * Search Modrinth for mods that have a build for the target and run on a server.
     *
     * @param  array{loader: string, version: string}  $target
     * @return array{mods: list<array{project: string, name: string, description: string, icon: string|null, downloads: int}>, more: bool}
     *
     * @throws ModrinthUnavailable
     */
    public static function search(array $target, string $query, int $offset): array
    {
        $found = self::modrinth('get', '/search', [
            'query' => $query,
            'facets' => json_encode([['project_type:mod'], ["categories:{$target['loader']}"], ["versions:{$target['version']}"], ['server_side:required', 'server_side:optional']]),
            'index' => $query === '' ? 'downloads' : 'relevance',
            'offset' => $offset,
            'limit' => 20,
        ]);

        $mods = [];
        foreach ($found['hits'] as $hit) {
            $mods[] = [
                'project' => (string) $hit['project_id'],
                'name' => (string) $hit['title'],
                'description' => (string) $hit['description'],
                'icon' => filled($hit['icon_url']) ? (string) $hit['icon_url'] : null,
                'downloads' => (int) $hit['downloads'],
            ];
        }

        return ['mods' => $mods, 'more' => $found['total_hits'] > $offset + 20];
    }

    /**
     * The jars in the server's mods folder. Those Modrinth knows by their fingerprint get a name,
     * icon and version; without Modrinth they are all listed by file name.
     *
     * @param  array{loader: string, version: string}|null  $target
     * @return list<array{file: string, size: int, project: string|null, name: string|null, icon: string|null, version: string|null, update: bool}>
     *
     * @throws ConnectionException When the node does not answer.
     * @throws RequestException
     */
    public static function installed(Server $server, ?array $target): array
    {
        $jars = (new NodeClient($server->node))->mods($server);
        $hashes = ['hashes' => array_column($jars, 'sha512'), 'algorithm' => 'sha512'];
        $known = $projects = $newer = [];

        try {
            if ($jars !== []) {
                $known = self::modrinth('post', '/version_files', $hashes);
            }
            if ($known !== []) {
                $projects = self::projects(array_column($known, 'project_id'));
                $newer = $target === null ? [] : self::modrinth('post', '/version_files/update', [...$hashes, 'loaders' => [$target['loader']], 'game_versions' => [$target['version']]]);
            }
        } catch (ModrinthUnavailable) {
            // The folder is still worth showing.
        }

        return array_map(function (array $jar) use ($known, $projects, $newer): array {
            $version = $known[$jar['sha512']] ?? null;
            $project = $projects[$version['project_id'] ?? ''] ?? null;

            return [
                'file' => $jar['name'],
                'size' => $jar['size'],
                'project' => $version['project_id'] ?? null,
                'name' => $project['title'] ?? null,
                'icon' => ($project['icon_url'] ?? null) ?: null,
                'version' => $version['version_number'] ?? null,
                'update' => isset($version, $newer[$jar['sha512']]) && $newer[$jar['sha512']]['id'] !== $version['id'],
            ];
        }, $jars);
    }

    /**
     * Install the newest build of a mod for the target, replacing the jar of that mod if there
     * is one. Required dependencies that are not installed come along, except on CurseForge and
     * FTB packs: their jars are often unknown to Modrinth, and a second copy of a mod keeps the
     * server from starting, so there they are only named.
     *
     * @param  array{loader: string, version: string}  $target
     * @return array{installed: list<string>, missing: list<array{project: string, name: string}>}
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws ModrinthUnavailable
     * @throws ValidationException When the mod has no build for the target.
     */
    public static function install(Server $server, array $target, string $project): array
    {
        $client = new NodeClient($server->node);
        $have = array_column(array_filter(self::installed($server, $target), fn (array $jar): bool => $jar['project'] !== null), 'file', 'project');
        $automatic = ! in_array($server->server_type, ['AUTO_CURSEFORGE', 'FTBA'], true);
        $queue = [$project];
        $installed = $missing = [];

        while ($queue !== []) {
            $next = array_shift($queue);
            $version = self::modrinth('get', "/project/{$next}/version", ['loaders' => json_encode([$target['loader']]), 'game_versions' => json_encode([$target['version']])])[0] ?? null;

            if ($version === null) {
                if ($next === $project) {
                    throw ValidationException::withMessages(['project' => __('This mod has no version for :loader :version.', $target)]);
                }
                $missing[] = $next;

                continue;
            }

            $file = Arr::first($version['files'], fn (array $file): bool => $file['primary']) ?? $version['files'][0];
            $client->installMod($server, $file['url'], $file['filename'], $file['hashes']['sha512']);
            $installed[] = $id = $version['project_id'];

            if (isset($have[$id]) && $have[$id] !== $file['filename']) {
                $client->deleteFile($server, "mods/{$have[$id]}");
            }

            foreach ($version['dependencies'] as $dependency) {
                $needed = $dependency['project_id'] ?? null;

                if ($dependency['dependency_type'] === 'required' && $needed !== null && ! isset($have[$needed])
                    && ! in_array($needed, [...$installed, ...$queue, ...$missing], true)) {
                    $automatic ? $queue[] = $needed : $missing[] = $needed;
                }
            }
        }

        $projects = self::projects([...$installed, ...$missing]);
        $name = fn (string $id): string => (string) ($projects[$id]['title'] ?? $id);

        return [
            'installed' => array_map($name, $installed),
            'missing' => array_map(fn (string $id): array => ['project' => $id, 'name' => $name($id)], $missing),
        ];
    }

    /**
     * Modrinth projects by id, for their titles and icons.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     *
     * @throws ModrinthUnavailable
     */
    private static function projects(array $ids): array
    {
        return array_column(self::modrinth('get', '/projects', ['ids' => json_encode(array_values(array_unique($ids)))]), null, 'id');
    }

    /**
     * Ask Modrinth, so that its failures can be told apart from the node's.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModrinthUnavailable
     */
    private static function modrinth(string $method, string $path, array $data = []): mixed
    {
        try {
            return Modpacks::http()->{$method}(self::MODRINTH.$path, $data)->throw()->json();
        } catch (ConnectionException|RequestException $exception) {
            throw new ModrinthUnavailable(previous: $exception);
        }
    }

    /**
     * What a modpack runs on, read from the catalogue the pack came from.
     *
     * @return array{loader: string, version: string}|null
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws ModrinthUnavailable
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
            $version = $version !== '' ? $version : (string) (Arr::last((array) Modpacks::http()->get($pack)->throw()->json('versions'))['id'] ?? '');
            $targets = array_column((array) Modpacks::http()->get("{$pack}/{$version}")->throw()->json('targets'), null, 'type');

            return self::pair($targets['modloader']['name'] ?? null, $targets['game']['version'] ?? null);
        }

        // A pasted link names the pack, and maybe a version, in its path.
        if (preg_match('#^https://modrinth\.com/modpack/([^/]+)(?:/version/([^/]+))?#', $project, $link)) {
            [$project, $version] = [$link[1], $link[2] ?? ''];
        }

        $found = self::modrinth('get', "/project/{$project}/version".($version !== '' ? "/{$version}" : ''));
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
