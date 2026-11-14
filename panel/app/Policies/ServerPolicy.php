<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Admins see every server; viewers only the ones shared with them. Seeing is not changing:
     * every write route also needs the manage-servers gate.
     */
    public function view(User $user, Server $server): bool
    {
        return $user->isAdmin() || $server->viewers()->whereKey($user->id)->exists();
    }
}
