<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

class MemoryLimit
{
    // Without a configured limit, this share of the host's memory may go to servers;
    // the rest is for the system, the panel and each JVM's own overhead.
    private const float HOST_SHARE = 0.9;

    /**
     * The most memory, in MB, that all servers together may be given; null when it cannot be known.
     */
    public static function mb(): ?int
    {
        $configured = Setting::value('memory_limit_mb');

        if (filled($configured)) {
            return (int) $configured;
        }

        $node = Node::query()->first();

        if ($node === null) {
            return null;
        }

        try {
            // A failed lookup is not cached, so it is tried again on the next request.
            $host = Cache::remember('host-memory-mb', now()->addHour(), fn (): int => (new NodeClient($node))->hostMemoryMb());
        } catch (ConnectionException|RequestException) {
            return null;
        }

        return $host > 0 ? (int) floor($host * self::HOST_SHARE) : null;
    }

    /**
     * The memory, in MB, still free under the limit, not counting the given server's own share.
     */
    public static function freeMb(?Server $except = null): ?int
    {
        $limit = self::mb();

        if ($limit === null) {
            return null;
        }

        return $limit - (int) Server::query()->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))->sum('memory_mb');
    }
}
