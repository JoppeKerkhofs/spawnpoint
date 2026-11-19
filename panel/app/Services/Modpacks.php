<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class Modpacks
{
    // CurseForge's ids for Minecraft and for its modpack category.
    private const int CURSEFORGE_MINECRAFT = 432;

    private const int CURSEFORGE_MODPACKS = 4471;

    /**
     * Search a platform for modpacks, most downloaded first.
     *
     * @param  'MODRINTH'|'AUTO_CURSEFORGE'|'FTBA'  $platform
     * @return list<array{project: string, name: string, description: string, icon: string|null, downloads: int}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function search(string $platform, string $query): array
    {
        return match ($platform) {
            'AUTO_CURSEFORGE' => self::curseforge($query),
            'FTBA' => self::ftb($query),
            default => self::modrinth($query),
        };
    }

    /**
     * List the versions of a pack, newest first, for the platforms where one can be chosen.
     *
     * @param  'MODRINTH'|'FTBA'  $platform
     * @return list<array{id: string, name: string}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function versions(string $platform, string $project): array
    {
        $versions = [];

        if ($platform === 'FTBA') {
            $pack = self::http()->get("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$project}")->throw()->json();

            foreach (array_reverse($pack['versions'] ?? []) as $version) {
                $versions[] = ['id' => (string) $version['id'], 'name' => "{$version['name']} ({$version['type']})"];
            }

            return array_slice($versions, 0, 50);
        }

        foreach (self::http()->get("https://api.modrinth.com/v2/project/{$project}/version")->throw()->json() as $version) {
            $versions[] = ['id' => (string) $version['id'], 'name' => (string) $version['version_number']];
        }

        return array_slice($versions, 0, 50);
    }

    /**
     * The newest release of a pack, which is what a server without a chosen version installs
     * when it starts. Null when there is none, or for a pasted Modrinth link.
     *
     * @return array{id: string, name: string}|null
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function newest(string $platform, string $project): ?array
    {
        if ($platform === 'AUTO_CURSEFORGE') {
            $pack = self::http()->withHeaders(['x-api-key' => (string) Setting::value('curseforge_key')])
                ->get('https://api.curseforge.com/v1/mods/search', ['gameId' => self::CURSEFORGE_MINECRAFT, 'classId' => self::CURSEFORGE_MODPACKS, 'slug' => $project])
                ->throw()
                ->json('data.0');

            if (! isset($pack['mainFileId'])) {
                return null;
            }

            $name = (string) $pack['mainFileId'];
            foreach ($pack['latestFiles'] ?? [] as $file) {
                $name = $file['id'] === $pack['mainFileId'] ? (string) $file['displayName'] : $name;
            }

            return ['id' => (string) $pack['mainFileId'], 'name' => $name];
        }

        if ($platform === 'FTBA') {
            foreach (array_reverse(self::http()->get("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$project}")->throw()->json('versions') ?? []) as $version) {
                if (strtolower((string) $version['type']) === 'release') {
                    return ['id' => (string) $version['id'], 'name' => (string) $version['name']];
                }
            }

            return null;
        }

        if (str_starts_with($project, 'https://')) {
            return null;
        }

        foreach (self::http()->get("https://api.modrinth.com/v2/project/{$project}/version")->throw()->json() as $version) {
            if ($version['version_type'] === 'release') {
                return ['id' => (string) $version['id'], 'name' => (string) $version['version_number']];
            }
        }

        return null;
    }

    /**
     * Where a launcher downloads a CurseForge pack: the file the server runs, or the newest.
     * The address is null when the pack's author forbids downloads outside CurseForge.
     *
     * @return array{project: int, file: int, archive: string|null, page: string|null}|null
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function curseforgePack(string $slug, ?string $file): ?array
    {
        $pack = self::curseforgeApi()
            ->get('https://api.curseforge.com/v1/mods/search', ['gameId' => self::CURSEFORGE_MINECRAFT, 'classId' => self::CURSEFORGE_MODPACKS, 'slug' => $slug])
            ->throw()
            ->json('data.0');
        $file = (int) ($file ?? $pack['mainFileId'] ?? 0);

        if (! isset($pack['id']) || $file === 0) {
            return null;
        }

        return [
            'project' => (int) $pack['id'],
            'file' => $file,
            'archive' => self::curseforgeApi()->get("https://api.curseforge.com/v1/mods/{$pack['id']}/files/{$file}")->throw()->json('data.downloadUrl'),
            'page' => $pack['links']['websiteUrl'] ?? null,
        ];
    }

    /**
     * Where each file of a CurseForge pack is downloaded, which only a key can ask. A file
     * whose author forbids outside downloads has no address, and a page to fetch it from by hand.
     *
     * @param  list<int>  $files
     * @return list<array{project: int, file: int, name: string, url: string|null, page: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function curseforgeFiles(array $files): array
    {
        $found = self::curseforgeApi()->post('https://api.curseforge.com/v1/mods/files', ['fileIds' => $files])->throw()->json('data') ?? [];
        $closed = array_values(array_unique(array_column(array_filter($found, fn (array $file): bool => blank($file['downloadUrl'] ?? null)), 'modId')));
        $pages = $closed === [] ? [] : array_column(
            self::curseforgeApi()->post('https://api.curseforge.com/v1/mods', ['modIds' => $closed])->throw()->json('data') ?? [],
            'links',
            'id',
        );

        return array_values(array_map(fn (array $file): array => [
            'project' => (int) $file['modId'],
            'file' => (int) $file['id'],
            'name' => (string) $file['fileName'],
            'url' => filled($file['downloadUrl'] ?? null) ? (string) $file['downloadUrl'] : null,
            'page' => filled($file['downloadUrl'] ?? null) || ! isset($pages[$file['modId']]['websiteUrl']) ? null : "{$pages[$file['modId']]['websiteUrl']}/files/{$file['id']}",
        ], $found));
    }

    private static function curseforgeApi(): PendingRequest
    {
        return self::http()->timeout(20)->withHeaders(['x-api-key' => (string) Setting::value('curseforge_key')]);
    }

    /**
     * FTB's search answers with pack numbers only, so each hit is looked up for its name and picture.
     *
     * @return list<array{project: string, name: string, description: string, icon: string|null, downloads: int}>
     */
    private static function ftb(string $query): array
    {
        // The search needs a few letters; without them this shows FTB's most played packs.
        $url = mb_strlen($query) >= 3
            ? 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/search/8?term='.rawurlencode($query)
            : 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/popular/plays/8';
        $packs = [];

        foreach (self::http()->get($url)->throw()->json('packs') ?? [] as $id) {
            $pack = self::http()->get("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$id}")->json();

            if (($pack['status'] ?? null) !== 'success') {
                continue;
            }

            $packs[] = [
                'project' => (string) $pack['id'],
                'name' => (string) $pack['name'],
                'description' => (string) ($pack['synopsis'] ?? ''),
                'icon' => self::squareArt($pack['art'] ?? []),
                'downloads' => (int) ($pack['installs'] ?? 0),
            ];
        }

        return $packs;
    }

    /**
     * @param  list<array{type?: string, url?: string}>  $art
     */
    private static function squareArt(array $art): ?string
    {
        foreach ($art as $picture) {
            if (($picture['type'] ?? null) === 'square') {
                return $picture['url'] ?? null;
            }
        }

        return null;
    }

    public static function http(): PendingRequest
    {
        return Http::connectTimeout(2)->timeout(5)->withUserAgent('self-hosted-minecraft-panel');
    }

    /**
     * @return list<array{project: string, name: string, description: string, icon: string|null, downloads: int}>
     */
    private static function modrinth(string $query): array
    {
        $hits = Http::connectTimeout(2)->timeout(5)
            ->withUserAgent('self-hosted-minecraft-panel')
            ->get('https://api.modrinth.com/v2/search', [
                'query' => $query,
                'facets' => '[["project_type:modpack"],["server_side:required","server_side:optional"]]',
                'index' => $query === '' ? 'downloads' : 'relevance',
                'limit' => 10,
            ])
            ->throw()
            ->json('hits');

        $packs = [];
        foreach ($hits as $hit) {
            $packs[] = [
                'project' => (string) $hit['slug'],
                'name' => (string) $hit['title'],
                'description' => (string) $hit['description'],
                'icon' => $hit['icon_url'] ?: null,
                'downloads' => (int) $hit['downloads'],
            ];
        }

        return $packs;
    }

    /**
     * @return list<array{project: string, name: string, description: string, icon: string|null, downloads: int}>
     */
    private static function curseforge(string $query): array
    {
        $mods = Http::connectTimeout(2)->timeout(5)
            ->withHeaders(['x-api-key' => (string) Setting::value('curseforge_key')])
            ->get('https://api.curseforge.com/v1/mods/search', [
                'gameId' => self::CURSEFORGE_MINECRAFT,
                'classId' => self::CURSEFORGE_MODPACKS,
                'searchFilter' => $query,
                // Sort field 6 is total downloads.
                'sortField' => 6,
                'sortOrder' => 'desc',
                'pageSize' => 10,
            ])
            ->throw()
            ->json('data');

        $packs = [];
        foreach ($mods as $mod) {
            $packs[] = [
                'project' => (string) $mod['slug'],
                'name' => (string) $mod['name'],
                'description' => (string) $mod['summary'],
                'icon' => $mod['logo']['thumbnailUrl'] ?? null,
                'downloads' => (int) $mod['downloadCount'],
            ];
        }

        return $packs;
    }
}
