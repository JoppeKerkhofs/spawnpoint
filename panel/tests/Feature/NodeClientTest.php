<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('signature matches the vector the rust service is tested against', function () {
    expect(NodeClient::signature('0123456789abcdef0123456789abcdef', 1000, 'POST', '/servers?x=1', '{}'))
        ->toBe('57e5b9892cd325a986ffcf28a917d5ba90799aa4e9ca4c683bc6b8e7cc6e7fbf');
});

test('requests to a node are signed with its token over the exact body sent', function () {
    $this->freezeTime();
    $server = Server::factory()->create();
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    (new NodeClient($server->node))->apply($server);

    Http::assertSent(fn (Request $request) => $request->header('X-Timestamp') === [(string) now()->getTimestamp()]
        && $request->header('X-Signature') === [NodeClient::signature(
            $server->node->token,
            now()->getTimestamp(),
            'PUT',
            "/servers/{$server->slug}",
            $request->body(),
        )]);
});

test('dashboard shows each server with the state its node reports', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake(['node.test:8080/servers' => Http::response([
        ['slug' => 'survival', 'state' => 'running', 'detail' => null],
    ])]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('servers.0.hostname', $server->hostname)
            ->missing('states')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('states.online', true)
                ->where('states.servers.survival.state', 'running'),
            ),
        );
});

test('dashboard reports the service as offline when it rejects or drops the call', function (Closure $fakeResponse) {
    Node::factory()->create();
    Http::fake(['node.test:8080/servers' => $fakeResponse]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('states.online', false)
                ->where('states.servers', []),
            ),
        );
})->with([
    'unreachable' => [fn () => fn () => Http::failedConnection()],
    'wrong token' => [fn () => fn () => Http::response(status: 401)],
]);
