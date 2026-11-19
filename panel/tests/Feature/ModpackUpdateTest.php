<?php

use App\Jobs\UpdateModpack;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function packServer(array $overrides = []): Server
{
    return Server::factory()->for(Node::factory()->create(['base_url' => 'http://node.test:8080']))->create([
        'slug' => 'cobblemon',
        'name' => 'Cobblemon',
        'server_type' => 'MODRINTH',
        'modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null, 'version' => 'OLD', 'version_name' => '1.0'],
        'pack_check' => true,
        ...$overrides,
    ]);
}

function modrinthVersions(): array
{
    return [
        ['id' => 'BETA', 'version_number' => '1.2-beta', 'version_type' => 'beta'],
        ['id' => 'NEW', 'version_number' => '1.1', 'version_type' => 'release'],
        ['id' => 'OLD', 'version_number' => '1.0', 'version_type' => 'release'],
    ];
}

test('a new release of a pack is reported once, to the webhooks that follow updates', function () {
    $server = packServer();
    packServer(['slug' => 'ignored', 'pack_check' => false]);
    User::factory()->admin()->create()->webhooks()->create(['name' => 'Hook', 'url' => 'https://93.184.216.34/hook', 'format' => 'slack', 'events' => ['updates']]);
    Http::fake(['api.modrinth.com/*' => Http::response(modrinthVersions()), '93.184.216.34/*' => Http::response()]);

    $this->artisan('modpacks:check')->assertSuccessful();
    $this->artisan('modpacks:check')->assertSuccessful();

    expect(ActivityLog::sole()->only('actor', 'event', 'subject', 'properties'))
        ->toBe(['actor' => 'System', 'event' => 'modpack.outdated', 'subject' => 'cobblemon', 'properties' => ['version' => '1.1']])
        ->and($server->refresh()->pack_latest)->toBe('NEW')
        ->and($server->modpack['version'])->toBe('OLD');
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.34/hook' && $request['text'] === 'Version 1.1 of the modpack of Cobblemon is out.');
});

test('a server that installs the newest version itself is only told about versions after the first look', function () {
    Queue::fake();
    $server = packServer(['modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null, 'version' => null], 'pack_auto_update' => true]);
    Http::fake(['api.modrinth.com/*' => Http::sequence()->push(array_slice(modrinthVersions(), 2))->push(modrinthVersions())]);

    $this->artisan('modpacks:check');
    Queue::assertNothingPushed();

    $this->artisan('modpacks:check');
    Queue::assertPushed(fn (UpdateModpack $job) => $job->serverId === $server->id && $job->version === 'NEW' && $job->name === '1.1');
    expect(ActivityLog::count())->toBe(0);
});

test('an automatic update backs a running server up before it installs the version', function () {
    $server = packServer();
    Http::fake([
        'node.test:8080/servers' => Http::response([['slug' => 'cobblemon', 'state' => 'running', 'detail' => null]]),
        'node.test:8080/servers/cobblemon/backups' => Http::sequence()
            ->push(['fallback' => false], 202)
            ->push([['name' => '1794600000.tar.gz', 'status' => 'completed', 'by' => 'Before auto-update']]),
        'node.test:8080/*' => Http::response(status: 202),
    ]);

    $job = new UpdateModpack($server->id, 'NEW', '1.1');
    $job->poll = 0;
    $job->handle();

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all();
    expect($sent)->toBe(['GET /servers', 'POST /servers/cobblemon/backups', 'GET /servers', 'GET /servers/cobblemon/backups', 'PUT /servers/cobblemon'])
        ->and($server->refresh()->modpack['version'])->toBe('NEW')
        ->and($server->modpack['version_name'])->toBe('1.1')
        ->and(ActivityLog::sole()->only('event', 'properties'))->toBe(['event' => 'modpack.update', 'properties' => ['version' => '1.1']]);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['by'] === 'Before auto-update');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['modpack'] === 'https://modrinth.com/modpack/cobblemon-fabric/version/NEW');
});

test('an automatic update leaves the server alone when the backup fails', function () {
    $server = packServer();
    Http::fake([
        'node.test:8080/servers' => Http::response([['slug' => 'cobblemon', 'state' => 'running', 'detail' => null]]),
        'node.test:8080/servers/cobblemon/backups' => Http::sequence()
            ->push(['fallback' => false], 202)
            ->push([['name' => '1794600000.failed', 'status' => 'failed', 'by' => 'Before auto-update']]),
        'node.test:8080/*' => Http::response(status: 202),
    ]);

    $job = new UpdateModpack($server->id, 'NEW', '1.1');
    $job->poll = 0;
    $job->handle();

    expect($server->refresh()->modpack['version'])->toBe('OLD')
        ->and(ActivityLog::sole()->event)->toBe('modpack.update.failed');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('a server without a chosen version is restarted to update, and only while it runs', function (string $state, bool $restarted) {
    $server = packServer(['modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null, 'version' => null]]);
    Http::fake([
        'node.test:8080/servers' => Http::response([['slug' => 'cobblemon', 'state' => $state, 'detail' => null]]),
        'node.test:8080/servers/cobblemon/backups' => Http::sequence()
            ->push(['fallback' => false], 202)
            ->push([['name' => '1794600000.tar.gz', 'status' => 'completed', 'by' => 'Before auto-update']]),
        'node.test:8080/*' => Http::response(status: 202),
    ]);

    $job = new UpdateModpack($server->id, 'NEW', '1.1');
    $job->poll = 0;
    $job->handle();

    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/servers/cobblemon/restart')))->toHaveCount($restarted ? 1 : 0)
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/backups')))->toHaveCount($restarted ? 1 : 0);
})->with([
    'running' => ['running', true],
    'stopped' => ['offline', false],
]);

test('the form saves both choices, and installing needs the check', function () {
    $server = packServer(['pack_check' => false, 'pack_latest' => 'OLD']);
    Http::fake(['node.test:8080/*' => Http::response(status: 202), '*' => Http::response()]);
    $form = [
        'name' => 'Cobblemon', 'server_type' => 'MODRINTH', 'mc_version' => 'LATEST', 'java_version' => '21', 'memory_mb' => '4096',
        'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', 'modpack_project' => 'cobblemon-fabric',
    ];
    $this->actingAs(User::factory()->admin()->create());

    $this->put(route('servers.update', $server), [...$form, 'pack_check' => '1', 'pack_auto_update' => '1'])->assertSessionHasNoErrors();
    expect($server->refresh()->only('pack_check', 'pack_auto_update', 'pack_latest'))->toBe(['pack_check' => true, 'pack_auto_update' => true, 'pack_latest' => 'OLD']);

    // Another pack, and no check: what was seen of the old pack is forgotten.
    $this->put(route('servers.update', $server), [...$form, 'modpack_project' => 'another-pack', 'pack_auto_update' => '1'])->assertSessionHasNoErrors();
    expect($server->refresh()->only('pack_check', 'pack_auto_update', 'pack_latest'))->toBe(['pack_check' => false, 'pack_auto_update' => false, 'pack_latest' => null]);
});
