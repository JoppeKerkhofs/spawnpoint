<?php

namespace App\Events;

use App\Models\Server;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tells open pages that something changed, never what it changed to: the page reloads the part
 * concerned, so what a user may see is decided where it always was.
 */
class Changed implements ShouldBroadcastNow
{
    /**
     * @param  string|null  $server  The slug, or null for a change that belongs to no server.
     * @param  string  $what  "state", or the first part of an activity event such as "backup".
     */
    public function __construct(public ?string $server, public string $what) {}

    /**
     * Announce a change. Live updates are a convenience, so failing to reach the WebSocket
     * server never fails what caused the change.
     */
    public static function send(Server|string|null $server, string $what): void
    {
        try {
            event(new self($server instanceof Server ? $server->slug : $server, $what));
        } catch (Throwable $exception) {
            // Once a minute at most: without the realtime container every action would log this.
            if (Cache::add('live-unreachable', true, 60)) {
                report($exception);
            }
        }
    }

    /**
     * Who may know is decided now, not when a tab subscribed: the admins, and for a server the
     * viewers it is shared with at this moment.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $users = User::query()->where('role', 'admin')->pluck('id');

        if ($this->server !== null) {
            $users = $users->merge(User::query()->whereHas('servers', fn ($servers) => $servers->where('slug', $this->server))->pluck('id'));
        }

        return array_values($users->unique()->map(fn (int $id): PrivateChannel => new PrivateChannel("users.{$id}"))->all());
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /**
     * @return array{server: string|null, what: string}
     */
    public function broadcastWith(): array
    {
        return ['server' => $this->server, 'what' => $this->what];
    }
}
