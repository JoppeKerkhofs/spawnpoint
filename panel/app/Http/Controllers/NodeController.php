<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Node;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    /**
     * List the nodes: the hosts that run Minecraft servers.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Nodes', [
            'nodes' => Node::query()->withCount('servers')->orderBy('id')->get()->map(function (Node $node): array {
                try {
                    (new NodeClient($node))->servers();
                    $node->forceFill(['last_seen_at' => now()])->save();
                    $online = true;
                } catch (ConnectionException|RequestException) {
                    $online = false;
                }

                return [
                    ...$node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'memory_limit_mb'),
                    'online' => $online,
                    'last_seen_at' => $node->last_seen_at?->toIso8601String(),
                    'servers' => $node->servers_count,
                    'allocated_mb' => (int) $node->servers()->sum('memory_mb'),
                    'limit_mb' => $online ? MemoryLimit::mb($node) : null,
                    // Registered by the panel from its own environment on every start, so its address is not edited here.
                    'local' => $node->name === Node::LOCAL,
                ];
            }),
            // Only stored encrypted, so this is the one time the token is shown in full.
            'newNode' => $request->session()->get('new_node'),
        ]);
    }

    /**
     * Register a node and show the token its service must be started with.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $token = Str::random(64);

        $node = Node::create([...$data, 'base_url' => rtrim($data['base_url'], '/'), 'token' => $token]);
        ActivityLog::record('node.create', properties: ['name' => $node->name]);

        return back()->with('new_node', ['name' => $node->name, 'token' => $token]);
    }

    /**
     * Change a node's name, address or host settings.
     */
    public function update(Request $request, Node $node): RedirectResponse
    {
        $data = $request->validate($this->rules($node));

        // The local node's address comes from the panel's environment and would be put back on the next start.
        if ($node->name === Node::LOCAL) {
            unset($data['name'], $data['base_url']);
        } else {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }

        $node->update($data);
        ActivityLog::record('node.update', properties: ['name' => $node->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node saved.')]);

        return back();
    }

    /**
     * Remove a node that no server runs on any more.
     */
    public function destroy(Node $node): RedirectResponse
    {
        if ($node->servers()->exists()) {
            return back()->withErrors(['node' => __('Delete or move the servers on this node first.')]);
        }

        if ($node->name === Node::LOCAL) {
            return back()->withErrors(['node' => __('The local node belongs to the panel itself and comes back on every start.')]);
        }

        $node->delete();
        ActivityLog::record('node.delete', properties: ['name' => $node->name]);

        return back();
    }

    /**
     * Tell the panel's web server where a node is, so it can pass browser traffic on to it. Only
     * that web server may ask: it runs in the same container and asks over the loopback address.
     */
    public function upstream(Request $request, Node $node): HttpResponse
    {
        // The address of the connection itself, not one a proxy header claims.
        abort_unless(in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 404);

        return response()->noContent()->header('X-Node-Upstream', $node->upstream());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Node $node = null): array
    {
        $local = $node?->name === Node::LOCAL;

        return [
            'name' => [$local ? 'nullable' : 'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/', Rule::unique('nodes', 'name')->ignore($node)],
            // Over http the panel signs every request but does not encrypt it, so that belongs on a
            // private network; https is a node that was given a certificate (SPAWNPOINT_TLS_CERT).
            'base_url' => [$local ? 'nullable' : 'required', 'string', 'max:255', 'regex:/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?\/?$/'],
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
            'bluemap_url' => ['nullable', 'url:http,https', 'max:255'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:1024,4194304'],
        ];
    }
}
