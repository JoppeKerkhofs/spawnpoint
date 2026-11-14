<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class NodeClient
{
    public function __construct(private Node $node) {}

    /**
     * List the containers the node manages.
     *
     * @return list<array{id: string, name: string|null, image: string|null, state: string|null, status: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function containers(): array
    {
        $path = '/containers';
        $timestamp = now()->getTimestamp();

        return Http::baseUrl($this->node->base_url)
            ->connectTimeout(2)
            ->timeout(5)
            ->withHeaders([
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => self::signature($this->node->token, $timestamp, 'GET', $path, ''),
            ])
            ->get($path)
            ->throw()
            ->json();
    }

    /**
     * Sign a request the way the Rust service verifies it (service/src/main.rs).
     */
    public static function signature(string $token, int $timestamp, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}\n{$method}\n{$path}\n{$body}", $token);
    }
}
