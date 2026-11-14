<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class NodeClient
{
    public function __construct(private Node $node) {}

    /**
     * Get the live state of every server on every node, keyed by slug.
     *
     * @return array{online: bool, servers: array<string, array{state: string, detail: string|null, players: array<string, mixed>|null, usage: array<string, mixed>|null, disk_bytes: int|null, last_backup: int|null, mods_changed: int}>}
     */
    public static function states(): array
    {
        $nodes = Node::all();
        $online = $nodes->isNotEmpty();
        $servers = [];

        foreach ($nodes as $node) {
            try {
                $servers += (new self($node))->servers();
                $node->forceFill(['last_seen_at' => now()])->save();
            } catch (ConnectionException|RequestException) {
                $online = false;
            }
        }

        return ['online' => $online, 'servers' => $servers];
    }

    /**
     * Get the live state of the servers on this node, keyed by slug.
     *
     * @return array<string, array{state: string, detail: string|null, players: array<string, mixed>|null, usage: array<string, mixed>|null, disk_bytes: int|null, last_backup: int|null, mods_changed: int}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function servers(): array
    {
        $servers = [];

        foreach ($this->send('GET', '/servers')->json() as $server) {
            $servers[$server['slug']] = [
                'state' => $server['state'],
                'detail' => $server['detail'],
                // Only for a running server that answers: online, max and up to a dozen names.
                'players' => $server['players'] ?? null,
                // The latest sample of a running server: CPU percent and memory in bytes.
                'usage' => $server['usage'] ?? null,
                'disk_bytes' => $server['disk_bytes'] ?? null,
                // Unix seconds of the newest backup.
                'last_backup' => $server['last_backup'] ?? null,
                // Mod or plugin jars put in place since the server started, so not loaded yet.
                'mods_changed' => $server['mods_changed'] ?? 0,
            ];
        }

        return $servers;
    }

    /**
     * Create the server's container, or recreate it with its current settings.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function apply(Server $server, bool $start = false, ?string $importHost = null): void
    {
        $this->send('PUT', "/servers/{$server->slug}", [
            'hostname' => $server->hostname,
            'java_version' => $server->java_version,
            'server_type' => $server->server_type,
            'mc_version' => $server->mc_version,
            'memory_mb' => $server->memory_mb,
            'eula' => true,
            'motd' => $server->settings['motd'],
            'difficulty' => $server->settings['difficulty'],
            'mode' => $server->settings['mode'],
            'max_players' => $server->settings['max_players'],
            'modpack' => self::modpackReference($server),
            'modpack_version' => $server->server_type === 'FTBA' ? ($server->modpack['version'] ?? null) : null,
            'cf_api_key' => $server->server_type === 'AUTO_CURSEFORGE' ? Setting::value('curseforge_key') : null,
            'bluemap' => $server->bluemap_enabled,
            'ops' => $server->settings['ops'] ?? [],
            'whitelist' => $server->settings['whitelist_names'] ?? [],
            'whitelist_on' => (bool) ($server->settings['whitelist'] ?? false),
            'jvm_opts' => $server->settings['jvm_opts'] ?? '',
            'cpu_limit' => $server->settings['cpu_limit'] ?? null,
            'seed' => $server->settings['seed'] ?? null,
            // Only when the server is created: a world from the import folder to begin with.
            'import_host' => $importHost,
            'is_default' => $server->is_default,
            'start' => $start,
        ]);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function power(Server $server, string $action): void
    {
        $this->send('POST', "/servers/{$server->slug}/{$action}");
    }

    /**
     * Run a console command on the server and return what it answered.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function command(Server $server, string $command): string
    {
        return $this->send('POST', "/servers/{$server->slug}/command", ['command' => $command])->body();
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function delete(Server $server): void
    {
        $this->send('DELETE', "/servers/{$server->slug}");
    }

    /**
     * Get the WebSocket address of a server's live console, with a token that is valid for one minute.
     */
    public function consoleUrl(Server $server, bool $write): string
    {
        $token = self::token('console', $this->node->token, $server->slug, now()->getTimestamp() + 60, $write);

        return preg_replace('/^http/', 'ws', $this->publicUrl())."/servers/{$server->slug}/console?token={$token}";
    }

    /**
     * Get the address the browser uploads to or downloads from, with a token that is valid for five minutes.
     *
     * @param  'upload'|'download'  $action
     */
    public function fileUrl(Server $server, string $action): string
    {
        $token = self::token('files', $this->node->token, $server->slug, now()->getTimestamp() + 300, $action === 'upload');

        return "{$this->publicUrl()}/servers/{$server->slug}/{$action}?token={$token}";
    }

    /**
     * Mint a browser token the way the Rust service verifies it (service/src/main.rs).
     *
     * @param  'console'|'files'  $purpose  A token for one purpose is refused for the other.
     */
    public static function token(string $purpose, string $key, string $slug, int $expiresAt, bool $write): string
    {
        $payload = bin2hex(json_encode(['slug' => $slug, 'exp' => $expiresAt, 'write' => $write], JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', "{$purpose}\n".$payload, $key);
    }

    /**
     * List a folder in the server's data folder, folders first.
     *
     * @return list<array{name: string, dir: bool, size: int, modified: int}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function files(Server $server, string $path): array
    {
        return $this->send('GET', "/servers/{$server->slug}/files".self::query(['path' => $path]))->json();
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function readFile(Server $server, string $path): string
    {
        return $this->send('GET', "/servers/{$server->slug}/files/content".self::query(['path' => $path]))->body();
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function writeFile(Server $server, string $path, string $content): void
    {
        $this->send('PUT', "/servers/{$server->slug}/files/content".self::query(['path' => $path]), body: $content);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function makeFolder(Server $server, string $path): void
    {
        $this->send('POST', "/servers/{$server->slug}/files/mkdir", ['path' => $path]);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function renameFile(Server $server, string $from, string $to): void
    {
        $this->send('POST', "/servers/{$server->slug}/files/rename", ['from' => $from, 'to' => $to]);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function deleteFile(Server $server, string $path): void
    {
        $this->send('DELETE', "/servers/{$server->slug}/files".self::query(['path' => $path]));
    }

    /**
     * List a server's log files, newest first.
     *
     * @return list<array{name: string, size: int, modified: int}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function logs(Server $server): array
    {
        return $this->send('GET', "/servers/{$server->slug}/logs")->json();
    }

    /**
     * Read a log file, optionally only the lines that contain the search text.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function log(Server $server, string $name, string $search = ''): string
    {
        $query = $search === '' ? '' : self::query(['q' => $search]);

        return $this->send('GET', "/servers/{$server->slug}/logs/{$name}{$query}")->body();
    }

    /**
     * Sign a request the way the Rust service verifies it (service/src/main.rs).
     */
    public static function signature(string $token, int $timestamp, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}\n{$method}\n{$path}\n{$body}", $token);
    }

    /**
     * What the node is told to install. A chosen Modrinth version goes in as a link to that version's
     * page, the one form the Minecraft image accepts for a fixed version.
     */
    private static function modpackReference(Server $server): ?string
    {
        $pack = $server->modpack;

        if ($pack === null) {
            return null;
        }

        return $server->server_type === 'MODRINTH' && filled($pack['version'] ?? null) && ! str_starts_with($pack['project'], 'https://')
            ? "https://modrinth.com/modpack/{$pack['project']}/version/{$pack['version']}"
            : $pack['project'];
    }

    /**
     * Get the memory of the machine the node runs on, in MB.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function hostMemoryMb(): int
    {
        return (int) $this->send('GET', '/host')->json('memory_mb');
    }

    /**
     * List a server's backups, newest first.
     *
     * @return list<array{name: string, size: int, created: int, locked: bool, status: string, by: string|null, reason: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function backups(Server $server): array
    {
        return $this->send('GET', "/servers/{$server->slug}/backups")->json();
    }

    /**
     * Start a backup; afterwards the node deletes unlocked ones beyond the newest few or older than the age limit.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function createBackup(Server $server, string $by): void
    {
        $this->send('POST', "/servers/{$server->slug}/backups", [
            'keep' => $server->backup_keep,
            'max_age_days' => $server->backup_retention_days,
            'by' => $by,
        ]);
    }

    /**
     * Stop the backup that is running.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function cancelBackup(Server $server): void
    {
        $this->send('DELETE', "/servers/{$server->slug}/backups/running");
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function restoreBackup(Server $server, string $name): void
    {
        $this->send('POST', "/servers/{$server->slug}/backups/{$name}/restore");
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function lockBackup(Server $server, string $name, bool $locked): void
    {
        $this->send('POST', "/servers/{$server->slug}/backups/{$name}/lock", ['locked' => $locked]);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function deleteBackup(Server $server, string $name): void
    {
        $this->send('DELETE', "/servers/{$server->slug}/backups/{$name}");
    }

    /**
     * Get the address the browser downloads a backup from, with a token that is valid for five minutes.
     */
    public function backupUrl(Server $server, string $name): string
    {
        $token = self::token('files', $this->node->token, $server->slug, now()->getTimestamp() + 300, false);

        return "{$this->publicUrl()}/servers/{$server->slug}/backups/{$name}/download?token={$token}";
    }

    /**
     * List the worlds in the node's import folder, as paths inside it.
     *
     * @return list<string>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function imports(): array
    {
        return $this->send('GET', '/imports')->json();
    }

    /**
     * Replace the server's world with one from a zip in its folder or from the import folder.
     *
     * @param  'zip'|'host'  $source
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function importWorld(Server $server, string $source, string $path, bool $start = false): void
    {
        $this->send('POST', "/servers/{$server->slug}/import", ['source' => $source, 'path' => $path, 'start' => $start]);
    }

    /**
     * The address the browser uses for the node, which may differ from the one the panel uses.
     */
    private function publicUrl(): string
    {
        $public = config('panel.node_public_url');

        return filled($public) ? rtrim(url($public), '/') : $this->node->base_url;
    }

    /**
     * Build a query string that is signed exactly as it is sent.
     *
     * @param  array<string, string>  $parameters
     */
    public static function query(array $parameters): string
    {
        return '?'.http_build_query($parameters, encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>|null  $data  Sent as JSON, unless a raw body is given.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function send(string $method, string $path, ?array $data = null, string $body = ''): Response
    {
        $body = $data === null ? $body : json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();

        return Http::baseUrl($this->node->base_url)
            ->connectTimeout(2)
            ->timeout(5)
            ->withHeaders([
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => self::signature($this->node->token, $timestamp, $method, $path, $body),
            ])
            ->withBody($body, 'application/json')
            ->send($method, $path)
            ->throw();
    }
}
