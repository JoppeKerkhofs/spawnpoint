<?php

use App\Jobs\MoveServer;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->node = Node::factory()->create(['name' => 'home', 'base_url' => 'http://node.test:8080']);
    $this->admin = User::factory()->admin()->create();
    // The list of live states is read back for the answer, so it needs a body.
    Http::fake(['node.test:8080/servers' => Http::response([]), 'node.test:8080/*' => Http::response(status: 202)]);
});

test('a token with servers:manage creates a server through the same action as the web form', function () {
    $token = $this->admin->createToken('Phone', ['servers:manage'])->plainTextToken;

    $this->postJson('/api/v1/servers', [
        'name' => 'Survival', 'slug' => 'survival', 'server_type' => 'PAPER', 'mc_version' => '1.21.10',
        'java_version' => '21', 'memory_mb' => '4096', 'motd' => 'hi', 'difficulty' => 'normal',
        'mode' => 'survival', 'max_players' => '20', 'eula' => '1',
    ], bearer($token))->assertStatus(201)->assertJsonPath('data.slug', 'survival');

    expect(Server::sole()->name)->toBe('Survival');
});

test('configuring is a different ability from creating', function () {
    $server = Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $creator = $this->admin->createToken('Creator', ['servers:manage'])->plainTextToken;

    $this->putJson('/api/v1/servers/survival', ['name' => 'Renamed'], bearer($creator))
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token lacks the servers:configure ability.');

    $configurer = $this->admin->createToken('Configurer', ['servers:configure'])->plainTextToken;
    $this->putJson('/api/v1/servers/survival', [
        'name' => 'Renamed', 'server_type' => 'PAPER', 'mc_version' => '1.21.10', 'java_version' => '21',
        'memory_mb' => '4096', 'motd' => 'hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20',
    ], bearer($configurer))->assertOk();

    expect($server->refresh()->name)->toBe('Renamed');
});

test('a viewer token cannot create or delete, whatever its abilities say', function () {
    $viewer = User::factory()->create();
    $server = Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $server->viewers()->attach($viewer, ['permissions' => json_encode(['power'])]);
    $token = $viewer->createToken('Phone', ['servers:manage'])->plainTextToken;

    $this->postJson('/api/v1/servers', [], bearer($token))->assertStatus(403);
    $this->deleteJson('/api/v1/servers/survival', [], bearer($token))->assertStatus(403);
});

test('a move is queued and a delete removes the server', function () {
    Queue::fake();
    $elsewhere = Node::factory()->create(['name' => 'attic', 'base_url' => 'http://attic.test:8080', 'memory_limit_mb' => 8192]);
    Server::factory()->for($this->node)->create(['slug' => 'survival', 'memory_mb' => 2048]);
    $token = $this->admin->createToken('Phone', ['servers:manage'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/move', ['node_id' => $elsewhere->id], bearer($token))
        ->assertStatus(202);
    Queue::assertPushed(MoveServer::class, 1);

    $this->deleteJson('/api/v1/servers/survival', [], bearer($token))->assertStatus(204);
    expect(Server::count())->toBe(0);
});

test('stop-all carries on when one server refuses', function () {
    Http::swap(new Factory);
    Server::factory()->for($this->node)->create(['slug' => 'busy']);
    Server::factory()->for($this->node)->create(['slug' => 'idle']);
    Http::fake([
        'node.test:8080/servers' => Http::response([
            ['slug' => 'busy', 'state' => 'running', 'detail' => null],
            ['slug' => 'idle', 'state' => 'running', 'detail' => null],
        ]),
        'node.test:8080/servers/busy/stop' => Http::response(status: 409),
        'node.test:8080/*' => Http::response(status: 202),
    ]);
    $token = $this->admin->createToken('Phone', ['servers:power'])->plainTextToken;

    $this->postJson('/api/v1/servers/stop-all', [], bearer($token))
        ->assertStatus(202)
        ->assertJsonPath('failed', ['busy']);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/servers/idle/stop'));
});
