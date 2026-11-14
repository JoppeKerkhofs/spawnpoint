<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Server;
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
     * @return array{online: bool, servers: array<string, array{state: string, detail: string|null}>}
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
     * @return array<string, array{state: string, detail: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function servers(): array
    {
        $servers = [];

        foreach ($this->send('GET', '/servers')->json() as $server) {
            $servers[$server['slug']] = ['state' => $server['state'], 'detail' => $server['detail']];
        }

        return $servers;
    }

    /**
     * Create the server's container, or recreate it with its current settings.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function apply(Server $server, bool $start = false): void
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
        $token = self::consoleToken($this->node->token, $server->slug, now()->getTimestamp() + 60, $write);

        return preg_replace('/^http/', 'ws', $this->node->base_url)."/servers/{$server->slug}/console?token={$token}";
    }

    /**
     * Mint a console token the way the Rust service verifies it (service/src/main.rs).
     */
    public static function consoleToken(string $key, string $slug, int $expiresAt, bool $write): string
    {
        $payload = bin2hex(json_encode(['slug' => $slug, 'exp' => $expiresAt, 'write' => $write], JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', "console\n".$payload, $key);
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
        $query = $search === '' ? '' : '?'.http_build_query(['q' => $search], encoding_type: PHP_QUERY_RFC3986);

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
     * @param  array<string, mixed>|null  $data
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function send(string $method, string $path, ?array $data = null): Response
    {
        $body = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR);
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
