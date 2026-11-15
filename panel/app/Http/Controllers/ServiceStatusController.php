<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

class ServiceStatusController extends Controller
{
    /**
     * Report which nodes answer, for the indicator in the header.
     */
    public function __invoke(): JsonResponse
    {
        $offline = [];

        // ponytail: the panel asks each node; switch to a heartbeat from the nodes if there are many.
        foreach (Node::all() as $node) {
            try {
                (new NodeClient($node))->servers();
                $node->forceFill(['last_seen_at' => now()])->save();
            } catch (ConnectionException|RequestException) {
                $offline[] = $node->name;
            }
        }

        return response()->json([
            'online' => $offline === [] && Node::query()->exists(),
            'nodes' => Node::query()->count(),
            'offline' => $offline,
            'last_seen_at' => Node::query()->latest('last_seen_at')->first()?->last_seen_at?->toIso8601String(),
        ]);
    }
}
