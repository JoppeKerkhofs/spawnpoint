<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
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
     * @param  'MODRINTH'|'AUTO_CURSEFORGE'  $platform
     * @return list<array{project: string, name: string, description: string, icon: string|null, downloads: int}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function search(string $platform, string $query): array
    {
        return $platform === 'AUTO_CURSEFORGE' ? self::curseforge($query) : self::modrinth($query);
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
            ->withHeaders(['x-api-key' => (string) config('panel.curseforge_key')])
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
