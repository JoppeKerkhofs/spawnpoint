<?php

namespace App\Actions\Servers;

use App\Models\ActivityLog;
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
        $pack = $server->modpack['project'] ?? null;

        DB::transaction(function () use ($server, $data, $pack): void {
            $server->update([
                'name' => $data['name'],
                'server_type' => $data['server_type'],
                'mc_version' => $data['mc_version'],
                'java_version' => $data['java_version'],
                'memory_mb' => $data['memory_mb'],
                // The seed is chosen once, when the server is created.
                'settings' => [...self::settings($data), 'seed' => $server->settings['seed'] ?? null],
                'modpack' => self::modpack($data),
                'bluemap_enabled' => (bool) ($data['bluemap'] ?? false),
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            self::demoteOtherDefaults($server);
            // Another pack: its picture is only fetched while the old one is not in the way.
            if ($server->icon === null && ($server->modpack['project'] ?? null) !== $pack) {
                rescue(fn () => (new NodeClient($server->node))->deleteFile($server, 'server-icon.png'), report: false);
            }
            (new NodeClient($server->node))->apply($server);
            ActivityLog::record('server.update', $server);
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
     * @return array{project: string, name: string, icon: string|null, version: string|null, version_name: string|null}|null
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
            'version' => $data['modpack_version'] ?? null,
            'version_name' => $data['modpack_version_name'] ?? null,
        ];
    }

    /**
     * Pick the game settings out of validated form data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function settings(array $data): array
    {
        return [
            'motd' => (string) ($data['motd'] ?? ''),
            'difficulty' => $data['difficulty'],
            'mode' => $data['mode'],
            'max_players' => (int) $data['max_players'],
            'ops' => self::names((string) ($data['ops'] ?? '')),
            'whitelist' => (bool) ($data['whitelist'] ?? false),
            'whitelist_names' => self::names((string) ($data['whitelist_names'] ?? '')),
            'jvm_opts' => (string) ($data['jvm_opts'] ?? ''),
            'cpu_limit' => filled($data['cpu_limit'] ?? null) ? (float) $data['cpu_limit'] : null,
        ];
    }

    /**
     * Split a list of player names typed with commas, spaces or line breaks.
     *
     * @return list<string>
     */
    public static function names(string $typed): array
    {
        return array_values(array_unique(preg_split('/[\s,]+/', $typed, flags: PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
