<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('console token matches the vector the rust service is tested against', function () {
    expect(NodeClient::token('console', '0123456789abcdef0123456789abcdef', 'survival', 2000, true))
        ->toEndWith('.b909a93d1a9fdbd6306efa948ceecf684803eabbe576da1c82220feb435d7250');
});

test('the console page hands out a short-lived token for that server only', function () {
    $this->freezeTime();
    $server = Server::factory()->for(Node::factory()->state(['base_url' => 'https://node.test:8443']))->create(['slug' => 'survival']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.show', $server))
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/Console')
            ->where('console.url', 'wss://node.test:8443/servers/survival/console?token='
                .NodeClient::token('console', $server->node->token, 'survival', now()->getTimestamp() + 60, true)),
        );
});

test('logs are listed and searched through the node', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake([
        'node.test:8080/servers/survival/logs' => Http::response([
            ['name' => 'latest.log', 'size' => 120, 'modified' => 1794600000],
            ['name' => '2026-11-13-1.log.gz', 'size' => 80, 'modified' => 1794500000],
        ]),
        'node.test:8080/servers/survival/logs/2026-11-13-1.log.gz?q=joined%20the%20game' => Http::response("Steve joined the game\n"),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.logs', [$server, 'file' => '2026-11-13-1.log.gz', 'q' => 'joined the game']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/Logs')
            ->where('files.0.name', 'latest.log')
            ->where('file', '2026-11-13-1.log.gz')
            ->where('content', "Steve joined the game\n")
            ->where('error', null),
        );

    // The signature must cover the query exactly as it is sent.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '?q=joined%20the%20game')
        && $request->header('X-Signature') === [NodeClient::signature(
            $server->node->token,
            (int) $request->header('X-Timestamp')[0],
            'GET',
            '/servers/survival/logs/2026-11-13-1.log.gz?q=joined%20the%20game',
            '',
        )]);
});

test('a log name that is not a plain log file never reaches the node', function (string $file) {
    $server = Server::factory()->create();
    Http::fake();
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('servers.logs', [$server, 'file' => $file]))->assertSessionHasErrors('file');
    $this->get(route('servers.logs.download', [$server, 'file' => $file]))->assertSessionHasErrors('file');

    Http::assertNothingSent();
})->with(['../server.properties', 'ops.json', '..%2Fworld%2Flevel.dat', 'a/b.log', '.hidden.log']);

test('a log can be downloaded as text', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake(['node.test:8080/servers/survival/logs/latest.log' => Http::response("line one\n")]);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.logs.download', [$server, 'file' => 'latest.log']));

    $response->assertDownload('survival-latest.log');
    expect($response->streamedContent())->toBe("line one\n");
});

test('the service status reports online with the time of last contact', function () {
    $this->freezeTime();
    $node = Node::factory()->create();
    Http::fake(['node.test:8080/servers' => Http::response([])]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('service-status'))
        ->assertExactJson(['online' => true, 'nodes' => 1, 'offline' => [], 'last_seen_at' => now()->toIso8601String()]);

    expect($node->refresh()->last_seen_at->timestamp)->toBe(now()->timestamp);
});

test('the service status reports offline and keeps the last contact time', function () {
    $node = Node::factory()->create();
    Http::fake(['node.test:8080/servers' => Http::failedConnection()]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('service-status'))
        ->assertExactJson(['online' => false, 'nodes' => 1, 'offline' => [$node->name], 'last_seen_at' => null]);

    expect($node->refresh()->last_seen_at)->toBeNull();
});
