<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show every node with the containers it manages.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'nodes' => Inertia::defer(fn () => Node::query()->orderBy('name')->get()->map(function (Node $node) {
                try {
                    $containers = (new NodeClient($node))->containers();
                } catch (ConnectionException|RequestException) {
                    $containers = null;
                }

                return [
                    'id' => $node->id,
                    'name' => $node->name,
                    'online' => $containers !== null,
                    'containers' => $containers ?? [],
                ];
            })),
        ]);
    }
}
