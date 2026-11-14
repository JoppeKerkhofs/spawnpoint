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
