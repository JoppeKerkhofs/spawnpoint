<?php

use App\Jobs\MoveServer;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['panel.base_domain' => 'mc.example.com']);
    $this->home = Node::factory()->create(['name' => 'home', 'base_url' => 'http://home.test:8080']);
    $this->attic = Node::factory()->create(['name' => 'attic', 'base_url' => 'http://attic.test:8080']);
    $this->admin = User::factory()->admin()->create();
});

test('an admin registers a node and sees its token once', function () {
    Http::fake(['*' => Http::response([])]);
    $this->actingAs($this->admin);

    $this->post(route('nodes.store'), ['name' => 'cellar', 'base_url' => 'http://10.0.0.7:8080/'])->assertSessionHas('new_node');
    $token = session('new_node')['token'];

    $node = Node::firstWhere('name', 'cellar');
    expect($node->base_url)->toBe('http://10.0.0.7:8080')
        ->and($node->token)->toBe($token)
        ->and($node->getRawOriginal('token'))->not->toContain($token);

    $this->get(route('nodes.index'))->assertInertia(fn (Assert $page) => $page->has('nodes', 3)->where('nodes.2.online', true));

    $this->post(route('nodes.store'), ['name' => 'cellar', 'base_url' => 'http://10.0.0.8:8080'])->assertSessionHasErrors('name');
    $this->post(route('nodes.store'), ['name' => 'web', 'base_url' => 'https://evil.example/path'])->assertSessionHasErrors('base_url');
    $this->actingAs(User::factory()->create())->get(route('nodes.index'))->assertForbidden();
});

test('a server is created on the chosen node, with that node\'s dns target', function () {
    config(['panel.cloudflare_token' => 'cf-token', 'panel.dns_target' => '203.0.113.5']);
    $this->attic->update(['dns_target' => '198.51.100.9']);
    Http::fake([
        'attic.test:8080/*' => Http::response(status: 202),
        'api.cloudflare.com/client/v4/zones?name=mc.example.com' => Http::response(['result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $this->actingAs($this->admin)
        ->post(route('servers.store'), [
            'name' => 'Survival', 'slug' => 'survival', 'server_type' => 'PAPER', 'mc_version' => '1.21.10', 'java_version' => '21',
            'memory_mb' => '4096', 'motd' => 'hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20',
            'eula' => '1', 'node_id' => (string) $this->attic->id,
        ])
        ->assertRedirect(route('servers.show', 'survival'));

    expect(Server::sole()->node_id)->toBe($this->attic->id);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://attic.test:8080/servers/survival' && $request->method() === 'PUT');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'home.test'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/dns_records') && $request['content'] === '198.51.100.9');
});

test('one node that does not answer leaves the others working', function () {
    Server::factory()->for($this->home)->create(['slug' => 'here']);
    Server::factory()->for($this->attic)->create(['slug' => 'there']);
    Http::fake([
        'home.test:8080/servers' => Http::response([['slug' => 'here', 'state' => 'running', 'detail' => null]]),
        'attic.test:8080/*' => Http::failedConnection(),
    ]);

    $states = NodeClient::states();

    expect($states['online'])->toBeFalse()
        ->and($states['servers']['here']['state'])->toBe('running')
        ->and($states['servers']['there']['state'])->toBe('unknown')
        ->and($states['servers']['there']['detail'])->toBe('The node attic did not answer.');

    $this->actingAs($this->admin)->getJson(route('service-status'))
        ->assertJsonPath('online', false)->assertJsonPath('nodes', 2)->assertJsonPath('offline', ['attic']);
});

test('memory is counted per node', function () {
    $this->home->update(['memory_limit_mb' => 8192]);
    $this->attic->update(['memory_limit_mb' => 4096]);
    Server::factory()->for($this->home)->create(['memory_mb' => 6144]);

    expect(MemoryLimit::freeMb($this->home))->toBe(2048)
        ->and(MemoryLimit::freeMb($this->attic))->toBe(4096)
        ->and(MemoryLimit::totalMb())->toBe(12288);
});

test('a node with servers cannot be deleted, an empty one can', function () {
    Server::factory()->for($this->home)->create();
    $this->actingAs($this->admin);

    $this->delete(route('nodes.destroy', $this->home))->assertSessionHasErrors('node');
    $this->delete(route('nodes.destroy', $this->attic))->assertSessionHasNoErrors();

    expect(Node::pluck('name')->all())->toBe(['home']);
});

test('only the panel\'s own web server learns where a node is', function () {
    $this->get(route('nodes.upstream', $this->attic), ['X-Forwarded-For' => '127.0.0.1'])
        ->assertNoContent()->assertHeader('X-Node-Upstream', 'attic.test:8080');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get(route('nodes.upstream', $this->attic))->assertNotFound();
    $this->get('/node-upstream/999')->assertNotFound();
});

test('a node behind TLS is accepted and the relay is told to use it', function () {
    $this->actingAs($this->admin)
        ->post(route('nodes.store'), ['name' => 'remote', 'base_url' => 'https://node1.example.com'])
        ->assertSessionHasNoErrors();
    $this->post(route('nodes.store'), ['name' => 'bad', 'base_url' => 'ftp://node1.example.com'])
        ->assertSessionHasErrors('base_url');

    $remote = Node::firstWhere('name', 'remote');

    expect($remote->upstream())->toBe('https://node1.example.com:443');
    $this->get(route('nodes.upstream', $remote))->assertHeader('X-Node-Upstream', 'https://node1.example.com:443');
});

test('a server is moved to another node, which pulls its backup over', function () {
    $server = Server::factory()->for($this->home)->create(['slug' => 'survival', 'name' => 'Survival', 'memory_mb' => 2048]);
    $this->attic->update(['memory_limit_mb' => 8192]);
    Http::fake([
        'home.test:8080/servers/survival/backups' => Http::response([['name' => '1794600000.tar.gz', 'size' => 10, 'created' => 1794600000, 'locked' => false, 'status' => 'done', 'by' => null, 'reason' => null]]),
        // Nothing is busy: every poll finds the server stopped.
        '*/servers' => Http::response([['slug' => 'survival', 'state' => 'offline', 'detail' => null]]),
        '*' => Http::response(status: 202),
    ]);

    $job = new MoveServer($server->id, $this->attic->id, $this->admin->id);
    $job->poll = 0;
    $job->handle();

    expect($server->refresh()->node_id)->toBe($this->attic->id)
        ->and(ActivityLog::sole()->only('actor', 'event', 'properties'))
        ->toBe(['actor' => $this->admin->name, 'event' => 'server.move', 'properties' => ['from' => 'home', 'to' => 'attic']]);

    // Backed up where it was, created on the new node, pulled over from the old one, then given up.
    Http::assertSent(fn (Request $request) => $request->url() === 'http://home.test:8080/servers/survival/backups' && $request->method() === 'POST');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://attic.test:8080/servers/survival' && $request->method() === 'PUT');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://attic.test:8080/servers/survival/fetch'
        && str_starts_with($request['url'], 'http://home.test:8080/servers/survival/backups/1794600000.tar.gz/download?token='));
    Http::assertSent(fn (Request $request) => $request->url() === 'http://home.test:8080/servers/survival' && $request->method() === 'DELETE');
});

test('a move that the new node refuses leaves the server where it was', function () {
    $server = Server::factory()->for($this->home)->create(['slug' => 'survival', 'memory_mb' => 2048]);
    Http::fake([
        'home.test:8080/servers/survival/backups' => Http::response([['name' => '1794600000.tar.gz', 'size' => 10, 'created' => 1794600000, 'locked' => false, 'status' => 'done', 'by' => null, 'reason' => null]]),
        '*/servers' => Http::response([['slug' => 'survival', 'state' => 'offline', 'detail' => null]]),
        'attic.test:8080/servers/survival/fetch' => Http::response(status: 409),
        '*' => Http::response(status: 202),
    ]);

    $job = new MoveServer($server->id, $this->attic->id, $this->admin->id);
    $job->poll = 0;
    $job->handle();

    expect($server->refresh()->node_id)->toBe($this->home->id)
        ->and(ActivityLog::sole()->event)->toBe('server.move.failed');
    // The half-made server on the new node is cleaned up, the old one is untouched.
    Http::assertSent(fn (Request $request) => $request->url() === 'http://attic.test:8080/servers/survival' && $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request) => $request->url() === 'http://home.test:8080/servers/survival' && $request->method() === 'DELETE');
});

test('the move is queued by an admin, refused onto a node without room, and closed to viewers', function () {
    Queue::fake();
    $server = Server::factory()->for($this->home)->create(['slug' => 'survival', 'memory_mb' => 2048]);
    Http::fake(['*' => Http::response([])]);

    $this->actingAs($this->admin)
        ->post(route('servers.move', $server), ['node_id' => $this->home->id])->assertSessionHasErrors('node_id');

    $this->attic->update(['memory_limit_mb' => 1024]);
    $this->post(route('servers.move', $server), ['node_id' => $this->attic->id])->assertSessionHasErrors('node_id');

    $this->attic->update(['memory_limit_mb' => 8192]);
    $this->post(route('servers.move', $server), ['node_id' => $this->attic->id])->assertSessionHasNoErrors();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.move', $server), ['node_id' => $this->attic->id])->assertForbidden();

    Queue::assertPushed(MoveServer::class, 1);
});
