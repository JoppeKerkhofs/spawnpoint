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
            ]);

            (new NodeClient($server->node))->apply($server);
        });
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
