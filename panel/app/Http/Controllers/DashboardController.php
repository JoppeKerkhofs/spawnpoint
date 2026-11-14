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
        // ponytail: viewers see no servers until the server_user sharing table exists.
        $canManage = $request->user()->can('manage-servers');

        return Inertia::render('Dashboard', [
            'servers' => $canManage ? Server::query()->orderBy('name')->get() : [],
            'states' => Inertia::defer(fn () => $canManage ? NodeClient::states() : ['online' => true, 'servers' => []]),
        ]);
    }
}
