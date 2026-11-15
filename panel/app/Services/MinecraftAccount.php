<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class MinecraftAccount
{
    /**
     * Look a Java Edition account up by name with Mojang.
     *
     * @return array{id: string, name: string}|null The id without dashes and the name as Mojang spells it; null when nobody has that name.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function find(string $name): ?array
    {
        $response = Http::connectTimeout(3)->timeout(8)
            ->withUserAgent('self-hosted-minecraft-panel')
            ->get('https://api.mojang.com/users/profiles/minecraft/'.rawurlencode($name));

        if (in_array($response->status(), [204, 404], true)) {
            return null;
        }

        return ['id' => (string) $response->throw()->json('id'), 'name' => (string) $response->json('name')];
    }
}
