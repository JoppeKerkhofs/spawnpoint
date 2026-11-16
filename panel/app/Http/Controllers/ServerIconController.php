<?php

namespace App\Http\Controllers;

use App\Actions\Servers\SetServerIcon;
use App\Http\Requests\ServerIconRequest;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

class ServerIconController extends Controller
{
    /**
     * Set the picture players see in their server list and the panel shows for this server.
     */
    public function update(ServerIconRequest $request, Server $server, SetServerIcon $setIcon): RedirectResponse
    {
        $this->attempt(fn () => $setIcon($server, $request->png()), __('Picture saved. Players see it after the next restart.'));

        return back();
    }

    /**
     * Take the picture away again.
     */
    public function destroy(Server $server, SetServerIcon $setIcon): RedirectResponse
    {
        $this->attempt(fn () => $setIcon($server, null), __('Picture removed. Players see that after the next restart.'));

        return back();
    }
}
