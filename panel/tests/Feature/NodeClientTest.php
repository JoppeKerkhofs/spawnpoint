<?php

use App\Models\Node;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('signature matches the vector the rust service is tested against', function () {
    expect(NodeClient::signature('0123456789abcdef0123456789abcdef', 1000, 'POST', '/servers?x=1', '{}'))
        ->toBe('57e5b9892cd325a986ffcf28a917d5ba90799aa4e9ca4c683bc6b8e7cc6e7fbf');
});

test('requests to a node are signed with its token', function () {
    $this->freezeTime();
    $node = Node::factory()->create();
    Http::fake(['node.test:8080/containers' => Http::response([])]);

    (new NodeClient($node))->containers();

    Http::assertSent(fn (Request $request) => $request->header('X-Timestamp') === [(string) now()->getTimestamp()]
        && $request->header('X-Signature') === [NodeClient::signature($node->token, now()->getTimestamp(), 'GET', '/containers', '')]);
});

test('dashboard lists the containers of each node', function () {
    $node = Node::factory()->create();
    Http::fake(['node.test:8080/containers' => Http::response([
        ['id' => 'abc', 'name' => 'survival', 'image' => 'itzg/minecraft-server', 'state' => 'running', 'status' => 'Up 2 hours'],
    ])]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('nodes')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('nodes.0.name', $node->name)
                ->where('nodes.0.online', true)
                ->where('nodes.0.containers.0.name', 'survival')
                ->missing('nodes.0.token'),
            ),
        );
});

test('dashboard shows a node as offline when its service rejects or drops the call', function (Closure $fakeResponse) {
    Node::factory()->create();
    Http::fake(['node.test:8080/containers' => $fakeResponse]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('nodes.0.online', false)
                ->where('nodes.0.containers', []),
            ),
        );
})->with([
    'unreachable' => [fn () => fn () => Http::failedConnection()],
    'wrong token' => [fn () => fn () => Http::response(status: 401)],
]);
