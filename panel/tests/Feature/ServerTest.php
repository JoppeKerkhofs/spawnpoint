<?php

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function serverForm(array $overrides = []): array
{
    return [
        'name' => 'Survival',
        'slug' => 'survival',
        'server_type' => 'PAPER',
        'mc_version' => '1.21.10',
        'java_version' => '21',
        'memory_mb' => '4096',
        'motd' => 'Hello',
        'difficulty' => 'normal',
        'mode' => 'survival',
        'max_players' => '20',
        'eula' => '1',
        ...$overrides,
    ];
}

beforeEach(function () {
    config(['panel.base_domain' => 'mc.example.com']);
});

test('an admin creates a server and its node is told to create and start it', function () {
    Node::factory()->create();
    Http::fake(['node.test:8080/servers/survival' => Http::response(status: 202)]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('servers.store'), serverForm())
        ->assertRedirect(route('servers.show', 'survival'));

    $server = Server::sole();
    expect($server->hostname)->toBe('survival.mc.example.com')
        ->and($server->owner_id)->toBe($admin->id)
        ->and($server->eula_accepted_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->data() === [
            'hostname' => 'survival.mc.example.com',
            'java_version' => 21,
            'server_type' => 'PAPER',
            'mc_version' => '1.21.10',
            'memory_mb' => 4096,
            'eula' => true,
            'motd' => 'Hello',
            'difficulty' => 'normal',
            'mode' => 'survival',
            'max_players' => 20,
            'modpack' => null,
            'modpack_version' => null,
            'cf_api_key' => null,
            'bluemap' => false,
            'ops' => [],
            'whitelist' => [],
            'whitelist_on' => false,
            'jvm_opts' => '',
            'cpu_limit' => null,
            'seed' => null,
            'import_host' => null,
            'is_default' => false,
            'start' => true,
        ]);
});

test('the forms offer the releases from the mojang manifest, newest first', function () {
    $server = Server::factory()->create();
    Http::fake([
        'piston-meta.mojang.com/*' => Http::response(['versions' => [
            ['id' => '26.1-snapshot-3', 'type' => 'snapshot'],
            ['id' => '1.21.10', 'type' => 'release'],
            ['id' => '1.21.9', 'type' => 'release'],
            ['id' => 'b1.7.3', 'type' => 'old_beta'],
        ]]),
        'node.test:8080/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('servers.create'))
        ->assertInertia(fn (Assert $page) => $page->where('options.mcVersions', ['1.21.10', '1.21.9']));
    $this->get(route('servers.configuration', $server))
        ->assertInertia(fn (Assert $page) => $page->where('options.mcVersions', ['1.21.10', '1.21.9']));
});

test('the forms still load when mojang cannot be reached', function () {
    Http::fake(['piston-meta.mojang.com/*' => Http::failedConnection()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('options.mcVersions', []));
});

test('no server is kept when the node refuses to create it', function () {
    Node::factory()->create();
    Http::fake(['node.test:8080/*' => Http::failedConnection()]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('servers.create'))
        ->post(route('servers.store'), serverForm())
        ->assertRedirect(route('servers.create'))
        ->assertSessionHasErrors('node');

    expect(Server::count())->toBe(0);
});

test('a server cannot be created with invalid input', function (array $overrides, string $field) {
    Node::factory()->create();
    Server::factory()->create(['slug' => 'taken']);
    Http::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), serverForm($overrides))
        ->assertSessionHasErrors($field);

    Http::assertNothingSent();
})->with([
    'eula not accepted' => [['eula' => false], 'eula'],
    'hostname with a dot' => [['slug' => 'a.b'], 'slug'],
    'hostname with a path' => [['slug' => '../etc'], 'slug'],
    'hostname in use' => [['slug' => 'taken'], 'slug'],
    'unknown server type' => [['server_type' => 'CUSTOM'], 'server_type'],
    'version with extra text' => [['mc_version' => '1.21 -e X=1'], 'mc_version'],
    'unsupported java' => [['java_version' => 9], 'java_version'],
    'too little memory' => [['memory_mb' => 64], 'memory_mb'],
    'motd with a line break' => [['motd' => "a\nb"], 'motd'],
]);

test('viewers cannot create, change, control or delete servers', function () {
    $server = Server::factory()->create();
    Http::fake();
    $this->actingAs(User::factory()->create());

    $this->get(route('servers.create'))->assertForbidden();
    $this->post(route('servers.store'), serverForm())->assertForbidden();
    $this->get(route('servers.show', $server))->assertForbidden();
    $this->put(route('servers.update', $server), serverForm())->assertForbidden();
    $this->post(route('servers.power', [$server, 'stop']))->assertForbidden();
    $this->delete(route('servers.destroy', $server))->assertForbidden();

    Http::assertNothingSent();
    $this->get(route('servers.backups', $server))->assertForbidden();
    $this->post(route('servers.backups.store', $server))->assertForbidden();
    $this->post(route('servers.import', $server), ['source' => 'host', 'path' => 'old'])->assertForbidden();

    Http::assertNothingSent();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('servers', []));
});

test('changing a setting saves it and recreates the container without forcing a start', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake(['node.test:8080/servers/survival' => Http::response(status: 202)]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('servers.update', $server), serverForm(['slug' => 'renamed', 'java_version' => '17', 'difficulty' => 'hard']))
        ->assertSessionHasNoErrors();

    $server->refresh();
    expect($server->java_version)->toBe(17)
        ->and($server->settings['difficulty'])->toBe('hard')
        ->and($server->slug)->toBe('survival');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['java_version'] === 17
        && $request['difficulty'] === 'hard'
        && $request['start'] === false);
});

test('power actions are passed to the node', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake(['node.test:8080/servers/survival/stop' => Http::response(status: 202)]);
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('servers.power', [$server, 'stop']))->assertRedirect();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/servers/survival/stop'));

    $this->post('/servers/survival/power/format-disk')->assertNotFound();
});

test('deleting a server removes its container and its row', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake(['node.test:8080/servers/survival' => Http::response(status: 202)]);

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('dashboard'));

    expect(Server::count())->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('a server is kept when the node cannot delete its container', function () {
    $server = Server::factory()->create();
    Http::fake(['node.test:8080/*' => Http::response(status: 409)]);

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('servers.destroy', $server));

    expect(Server::count())->toBe(1);
});

test('with cloudflare set up a new server gets a dns record, and loses it when deleted', function () {
    config(['panel.cloudflare_token' => 'cf-token', 'panel.dns_target' => '203.0.113.5']);
    Node::factory()->create();
    Http::fake([
        'node.test:8080/*' => Http::response(status: 202),
        'api.cloudflare.com/client/v4/zones?name=mc.example.com' => Http::response(['result' => []]),
        'api.cloudflare.com/client/v4/zones?name=example.com' => Http::response(['result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/client/v4/zones/zone1/dns_records?name=survival.mc.example.com' => Http::response(['result' => [['id' => 'rec1']]]),
        'api.cloudflare.com/client/v4/zones/zone1/dns_records*' => Http::response(['success' => true]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('servers.store'), serverForm())->assertRedirect(route('servers.show', 'survival'));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone1/dns_records'
        && $request->hasHeader('Authorization', 'Bearer cf-token')
        && $request['type'] === 'A'
        && $request['name'] === 'survival.mc.example.com'
        && $request['content'] === '203.0.113.5'
        && $request['proxied'] === false);

    $this->delete(route('servers.destroy', Server::sole()));

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/rec1'));
});

test('a server is still created when cloudflare refuses the dns record', function () {
    config(['panel.cloudflare_token' => 'wrong', 'panel.dns_target' => 'host.example.net']);
    Node::factory()->create();
    Http::fake([
        'node.test:8080/*' => Http::response(status: 202),
        'api.cloudflare.com/*' => Http::response(['errors' => [['code' => 10000]]], 403),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), serverForm())
        ->assertRedirect(route('servers.show', 'survival'));

    expect(Server::count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'dns.failed')->exists())->toBeTrue();
});

test('stop all stops the servers that are up and leaves the rest alone', function () {
    $running = Server::factory()->create(['slug' => 'running']);
    Server::factory()->for($running->node)->create(['slug' => 'starting']);
    Server::factory()->for($running->node)->create(['slug' => 'resting']);
    Http::fake([
        'node.test:8080/servers' => Http::response([
            ['slug' => 'running', 'state' => 'running', 'detail' => null],
            ['slug' => 'starting', 'state' => 'starting', 'detail' => null],
            ['slug' => 'resting', 'state' => 'offline', 'detail' => null],
        ]),
        'node.test:8080/servers/*' => Http::response(status: 202),
    ]);

    $this->actingAs(User::factory()->create())->post(route('servers.stop-all'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->post(route('servers.stop-all'))->assertRedirect();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/running/stop'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/starting/stop'));
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/servers/resting/stop'));
});
