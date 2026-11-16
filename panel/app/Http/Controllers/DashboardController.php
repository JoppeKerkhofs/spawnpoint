<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * List the servers with their live state.
     */
    public function __invoke(Request $request): Response
    {
        // Admins see every server; viewers only the ones shared with them.
        $servers = Server::query()->visibleTo($request->user())->orderBy('name')->get();

        return Inertia::render('Dashboard', [
            'servers' => $servers->map(fn (Server $server): array => $server->forUser($request->user())),
            'memoryLimitMb' => fn () => $request->user()->can('manage-servers') ? MemoryLimit::totalMb() : null,
            'states' => Inertia::defer(fn () => NodeClient::recentStates($request->user())),
        ]);
    }
}
