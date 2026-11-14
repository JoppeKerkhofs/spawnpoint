<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class UpdateServer
{
    /**
     * Save new settings and have the node recreate the container; the data folder persists.
     *
     * @param  array<string, mixed>  $data  Validated by UpdateServerRequest.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function __invoke(Server $server, array $data): void
    {
        DB::transaction(function () use ($server, $data): void {
            $server->update([
                'name' => $data['name'],
                'server_type' => $data['server_type'],
                'mc_version' => $data['mc_version'],
                'java_version' => $data['java_version'],
                'memory_mb' => $data['memory_mb'],
                'settings' => self::settings($data),
                'modpack' => self::modpack($data),
                'bluemap_enabled' => (bool) ($data['bluemap'] ?? false),
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            self::demoteOtherDefaults($server);
            (new NodeClient($server->node))->apply($server);
        });
    }

    /**
     * Keep at most one default server: when this one is the default, the others stop being it.
     * Their containers are recreated to drop the router label, which restarts the running ones.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public static function demoteOtherDefaults(Server $server): void
    {
        if (! $server->is_default) {
            return;
        }

        foreach (Server::query()->where('is_default', true)->whereKeyNot($server->getKey())->with('node')->get() as $other) {
            $other->update(['is_default' => false]);
            (new NodeClient($other->node))->apply($other);
        }
    }

    /**
     * Pick the modpack out of validated form data; null for a plain loader.
     *
     * @param  array<string, mixed>  $data
     * @return array{project: string, name: string, icon: string|null}|null
     */
    public static function modpack(array $data): ?array
    {
        if (! in_array($data['server_type'], Server::PACK_TYPES, true)) {
            return null;
        }

        return [
            'project' => $data['modpack_project'],
            'name' => $data['modpack_name'] ?? $data['modpack_project'],
            'icon' => $data['modpack_icon'] ?? null,
        ];
    }

    /**
     * Pick the game settings out of validated form data.
     *
     * @param  array<string, mixed>  $data
     * @return array{motd: string, difficulty: string, mode: string, max_players: int}
     */
    public static function settings(array $data): array
    {
        return [
            'motd' => (string) ($data['motd'] ?? ''),
            'difficulty' => $data['difficulty'],
            'mode' => $data['mode'],
            'max_players' => (int) $data['max_players'],
        ];
    }
}
