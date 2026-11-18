<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\CloudflareDns;
use App\Services\NodeClient;
use App\Services\Proxy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Move a server to another node: back it up where it is, let the other node pull that archive
 * over and unpack it, and only then take the container away from the node it came from. The
 * data folder on the old node stays behind, as it does when a server is deleted.
 */
class MoveServer implements ShouldQueue
{
    use Queueable;

    // Half a move must never be done twice, so a failure is final and says what went wrong.
    public int $tries = 1;

    // A world of many gigabytes takes its time; the node is asked how far it is in the meantime.
    public int $timeout = 7200;

    public int $poll = 5;

    // States the node reports while it is still busy with the work this move asked for.
    private const array BUSY = ['backing up', 'restoring'];

    public function __construct(public int $serverId, public int $targetNodeId, public int $userId) {}

    /**
     * Carry the server over, or leave it where it is and record why it stayed.
     */
    public function handle(): void
    {
        $server = Server::query()->find($this->serverId);
        $target = Node::query()->find($this->targetNodeId);
        $user = User::query()->find($this->userId);

        if ($server === null || $target === null || $user === null || $server->node_id === $target->id) {
            return;
        }

        // So the trail names the admin who asked for the move, not the queue worker.
        request()->setUserResolver(fn (): User => $user);

        $source = $server->node;
        $from = new NodeClient($source);
        $to = new NodeClient($target);
        $applied = false;

        try {
            $running = ($from->servers()[$server->slug]['state'] ?? 'offline') === 'running';
            $from->power($server, 'stop');
            $from->createBackup($server, "Before a move to {$target->name}");
            $this->await($from, $server);

            $archive = $from->backups($server)[0]['name'] ?? null;
            if ($archive === null || ! str_ends_with($archive, '.tar.gz')) {
                throw new RuntimeException((string) __('The backup that the move needs was not made.'));
            }

            // The folder and the container on the new node, which the archive is then unpacked over.
            $to->apply($server, start: false);
            $applied = true;
            $to->fetch($server, $from->transferUrl($server, $archive));
            $this->await($to, $server);

            $server->forceFill(['node_id' => $target->id])->save();
            // Nothing is left running on the old node; its data folder stays, like a deleted server's.
            rescue(fn () => $from->delete($server));
            // Again: a container that goes takes its route along, also the one just written for it.
            Proxy::sync();
            $this->moveDns($server, $source, $target);

            if ($running) {
                $to->power($server, 'start');
            }

            ActivityLog::record('server.move', $server, ['from' => $source->name, 'to' => $target->name]);
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            // The server is still the old node's, so the half-made one on the new node goes away.
            if ($applied) {
                rescue(fn () => $to->delete($server));
            }

            report($exception);
            ActivityLog::record('server.move.failed', $server, [
                'from' => $source->name,
                'to' => $target->name,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Wait until the node has finished the backup or the restore it was asked for.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function await(NodeClient $client, Server $server): void
    {
        $deadline = now()->addSeconds($this->timeout);

        do {
            sleep($this->poll);
            $live = $client->servers()[$server->slug] ?? [];
            $state = $live['state'] ?? 'offline';

            if ($state === 'error') {
                throw new RuntimeException($live['detail'] ?? (string) __('The node reported an error.'));
            }
        } while (in_array($state, self::BUSY, true) && now()->lt($deadline));

        if (in_array($state, self::BUSY, true)) {
            throw new RuntimeException((string) __('The node did not finish in time.'));
        }
    }

    /**
     * Point the server's DNS record at the new node, when the two nodes are not at the same address.
     */
    private function moveDns(Server $server, Node $source, Node $target): void
    {
        if (! CloudflareDns::enabled() || $source->dns_target === $target->dns_target) {
            return;
        }

        // Best effort, as everywhere: a record that stays behind is fixed in Cloudflare.
        rescue(function () use ($server, $target): void {
            CloudflareDns::delete($server->hostname);
            CloudflareDns::create($server->hostname, $target->dns_target);
            ActivityLog::record('dns.create', $server, ['hostname' => $server->hostname]);
        });
    }
}
