<?php

namespace App\Services;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

class LiveListeners
{
    /**
     * Whether any page is connected to the WebSocket server right now. False as well when there
     * is no such server or it does not answer: then there is no one to tell anything.
     */
    public function any(): bool
    {
        try {
            $broadcaster = Broadcast::connection();

            return $broadcaster instanceof PusherBroadcaster
                && ((array) ($broadcaster->getPusher()->get('/channels')->channels ?? [])) !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
