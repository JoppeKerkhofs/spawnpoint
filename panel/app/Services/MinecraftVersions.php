<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MinecraftVersions
{
    private const string MANIFEST_URL = 'https://piston-meta.mojang.com/mc/game/version_manifest.json';

    /**
     * Get every Minecraft Java release from Mojang's version manifest, newest first.
     *
     * @return list<string> Empty when Mojang cannot be reached; that answer is not cached.
     */
    public static function releases(): array
    {
        try {
            return Cache::remember('minecraft-releases', now()->addHour(), function (): array {
                $releases = [];

                foreach (Http::connectTimeout(2)->timeout(5)->get(self::MANIFEST_URL)->throw()->json('versions') as $version) {
                    if ($version['type'] === 'release') {
                        $releases[] = (string) $version['id'];
                    }
                }

                return $releases;
            });
        } catch (ConnectionException|RequestException) {
            return [];
        }
    }
}
