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

    private static function http(): PendingRequest
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
