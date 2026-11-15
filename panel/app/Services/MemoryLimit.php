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
     * The most memory, in MB, that the servers on a node together may be given; null when it cannot
     * be known. The node's own limit comes first, then the panel-wide setting, then its host's memory.
     */
    public static function mb(?Node $node): ?int
    {
        if ($node === null) {
            return null;
        }

        $configured = $node->memory_limit_mb ?? Setting::value('memory_limit_mb');

        if (filled($configured)) {
            return (int) $configured;
        }

        try {
            // A failed lookup is not cached, so it is tried again on the next request.
            $host = Cache::remember("host-memory-mb:{$node->id}", now()->addHour(), fn (): int => (new NodeClient($node))->hostMemoryMb());
        } catch (ConnectionException|RequestException) {
            return null;
        }

        return $host > 0 ? (int) floor($host * self::HOST_SHARE) : null;
    }

    /**
     * The memory, in MB, still free on a node, not counting the given server's own share.
     */
    public static function freeMb(?Node $node, ?Server $except = null): ?int
    {
        $limit = self::mb($node);

        if ($limit === null || $node === null) {
            return null;
        }

        return $limit - (int) $node->servers()->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))->sum('memory_mb');
    }

    /**
     * The limits of all nodes together, for the overview; null when one of them is unknown.
     */
    public static function totalMb(): ?int
    {
        $total = 0;

        foreach (Node::all() as $node) {
            $limit = self::mb($node);

            if ($limit === null) {
                return null;
            }

            $total += $limit;
        }

        return $total > 0 ? $total : null;
    }
}
