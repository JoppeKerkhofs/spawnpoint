<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeClient;
use Illuminate\Http\JsonResponse;

class ServiceStatusController extends Controller
{
    /**
     * Report whether the Rust service answers, for the indicator in the header.
     */
    public function __invoke(): JsonResponse
    {
        // ponytail: the panel polls the service; switch to a heartbeat from the service if nodes move off this host.
        return response()->json([
            'online' => NodeClient::states()['online'],
            'last_seen_at' => Node::query()->latest('last_seen_at')->first()?->last_seen_at?->toIso8601String(),
        ]);
    }
}
