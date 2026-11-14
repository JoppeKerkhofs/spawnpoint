<?php

namespace App\Http\Controllers;

use App\Models\Server;
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
            'servers' => $servers,
            'states' => Inertia::defer(function () use ($servers): array {
                $states = NodeClient::states();
                // A viewer must not learn about servers that are not shared with them.
                $states['servers'] = array_intersect_key($states['servers'], $servers->keyBy('slug')->all());

                return $states;
            }),
        ]);
    }
}
