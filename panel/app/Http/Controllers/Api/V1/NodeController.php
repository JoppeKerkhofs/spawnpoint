<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\NodeValidationRules;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class NodeController extends Controller
{
    use NodeValidationRules;

    /**
     * The hosts that run servers, with whether they answered just now.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Node::query()->withCount('servers')->orderBy('id')->get()->map(function (Node $node): array {
            try {
                (new NodeClient($node))->servers();
                $online = true;
            } catch (ConnectionException|RequestException) {
                $online = false;
            }

            return [
                ...$node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'minecraft_port', 'maps_port', 'memory_limit_mb'),
                'online' => $online,
                'servers' => $node->servers_count,
                'allocated_mb' => (int) $node->servers()->sum('memory_mb'),
                'limit_mb' => $online ? MemoryLimit::mb($node) : null,
                'local' => $node->name === Node::LOCAL,
            ];
        })]);
    }

    /**
     * Register a node. Its token is in the answer and is never retrievable again.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->nodeRules());
        $token = Str::random(64);
        $node = Node::create([...$data, 'base_url' => rtrim($data['base_url'], '/'), 'token' => $token]);
        ActivityLog::record('node.create', properties: ['name' => $node->name]);

        return response()->json([
            'data' => $node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'minecraft_port', 'maps_port', 'memory_limit_mb'),
            // Stored encrypted, so this is the one time it can be read.
            'token' => $token,
        ], 201);
    }

    /**
     * Change a node's name, address or host settings.
     */
    public function update(Request $request, Node $node): JsonResponse
    {
        $data = $request->validate($this->nodeRules($node));

        // The local node's address comes from the panel's environment and would be put back on the next start.
        if ($node->name === Node::LOCAL) {
            unset($data['name'], $data['base_url']);
        } else {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }

        $node->update($data);
        ActivityLog::record('node.update', properties: ['name' => $node->name]);

        return response()->json(['data' => $node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'minecraft_port', 'maps_port', 'memory_limit_mb')]);
    }

    /**
     * Remove a node that no server runs on any more.
     */
    public function destroy(Node $node): JsonResponse|Response
    {
        if ($node->servers()->exists()) {
            return response()->json(['message' => __('Delete or move the servers on this node first.')], 422);
        }

        if ($node->name === Node::LOCAL) {
            return response()->json(['message' => __('The local node belongs to the panel itself and comes back on every start.')], 422);
        }

        $node->delete();
        ActivityLog::record('node.delete', properties: ['name' => $node->name]);

        return response()->noContent();
    }
}
