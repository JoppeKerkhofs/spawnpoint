<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Admins see every server; viewers only the ones shared with them. Seeing includes the
     * live console, read-only.
     */
    public function view(User $user, Server $server): bool
    {
        return $user->isAdmin() || $server->viewers()->whereKey($user->id)->exists();
    }

    /**
     * Whether the user holds one of Server::PERMISSIONS on this server. Routes name it,
     * quoted so it is not taken for a route parameter: can:act,server,"files".
     */
    public function act(User $user, Server $server, string $permission): bool
    {
        return in_array($permission, $server->permissionsFor($user), true);
    }
}
